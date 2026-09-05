<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Booking\Enums\BookingStatus;
use Modules\Booking\Exceptions\SlotCapacityExceededException;
use Modules\Booking\Models\AvailabilityRule;
use Modules\Booking\Models\Booking;
use Modules\Booking\Models\BookingResource;
use Modules\Booking\Models\BookingService as BookingServiceModel;
use Modules\Booking\Models\BookingSlot;
use Modules\Booking\Services\BookingHoldService;
use Modules\Booking\Services\BookingSlotGenerationService;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\CustomerProfile;
use Tests\TestCase;

/**
 * Owner Delta §5/§6/§8: capacity-safe holds — no cached counter, live
 * COUNT(*) under a row lock; a hold is idempotent per
 * (booking_slot_id, checkout_session_uuid) unconditionally, surviving
 * held -> confirmed.
 */
class BookingHoldServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CustomerProfile $profile;

    private BookingHoldService $holdService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Booking Tenant', 'slug' => 'booking-tenant']);
        $user = User::factory()->create();
        $this->profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        $this->holdService = app(BookingHoldService::class);
    }

    private function makeSlot(int $capacity): BookingSlot
    {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SVC-'.uniqid(), 'name' => 'Service', 'slug' => 'svc-'.uniqid(), 'product_type' => 'booking', 'status' => 'active']);
        $bookingService = BookingServiceModel::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'duration_minutes' => 60, 'timezone' => 'UTC', 'buffer_minutes' => 0]);
        $resource = BookingResource::create(['tenant_id' => $this->tenant->id, 'name' => 'Room', 'capacity' => $capacity]);
        $resource->eligibleServices()->attach($bookingService->id);

        return BookingSlot::create([
            'tenant_id' => $this->tenant->id,
            'booking_resource_id' => $resource->id,
            'booking_service_id' => $bookingService->id,
            'starts_at' => CarbonImmutable::now()->addDay(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
            'capacity' => $capacity,
        ]);
    }

    public function test_capacity_one_slot_rejects_a_second_hold(): void
    {
        $slot = $this->makeSlot(1);

        $this->holdService->hold($slot, $this->profile, $slot->booking_service_id, (string) Str::uuid(), (string) now()->addMinutes(15));

        $user2 = User::factory()->create();
        $profile2 = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user2->id]);

        $this->expectException(SlotCapacityExceededException::class);
        $this->holdService->hold($slot, $profile2, $slot->booking_service_id, (string) Str::uuid(), (string) now()->addMinutes(15));
    }

    public function test_a_retried_hold_from_the_same_checkout_session_returns_the_same_row_never_a_second_one(): void
    {
        $slot = $this->makeSlot(1);
        $checkoutUuid = (string) Str::uuid();

        $first = $this->holdService->hold($slot, $this->profile, $slot->booking_service_id, $checkoutUuid, (string) now()->addMinutes(15));
        $second = $this->holdService->hold($slot, $this->profile, $slot->booking_service_id, $checkoutUuid, (string) now()->addMinutes(15));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Booking::where('booking_slot_id', $slot->id)->count());
    }

    public function test_a_retried_hold_after_confirmation_still_returns_the_same_row_not_a_second_held_row(): void
    {
        $slot = $this->makeSlot(1);
        $checkoutUuid = (string) Str::uuid();

        $booking = $this->holdService->hold($slot, $this->profile, $slot->booking_service_id, $checkoutUuid, (string) now()->addMinutes(15));
        $this->holdService->confirmForCheckoutSession($checkoutUuid, 999);

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        // Owner Delta §5: the unconditional unique(slot, checkout_session_uuid)
        // means a retried hold call for the SAME session — even though the
        // row is now `confirmed`, not `held` — returns that same row.
        $retried = $this->holdService->hold($slot, $this->profile, $slot->booking_service_id, $checkoutUuid, (string) now()->addMinutes(15));
        $this->assertSame($booking->id, $retried->id);
        $this->assertSame(1, Booking::where('booking_slot_id', $slot->id)->count());
    }

    public function test_capacity_n_never_exceeds_n_confirmed_plus_held_bookings(): void
    {
        $slot = $this->makeSlot(3);

        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create();
            $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);
            $this->holdService->hold($slot, $profile, $slot->booking_service_id, (string) Str::uuid(), (string) now()->addMinutes(15));
        }

        $user4 = User::factory()->create();
        $profile4 = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user4->id]);

        $this->expectException(SlotCapacityExceededException::class);
        $this->holdService->hold($slot, $profile4, $slot->booking_service_id, (string) Str::uuid(), (string) now()->addMinutes(15));
    }

    public function test_expired_holds_are_cancelled_and_release_capacity(): void
    {
        $slot = $this->makeSlot(1);
        $booking = $this->holdService->hold($slot, $this->profile, $slot->booking_service_id, (string) Str::uuid(), (string) now()->subMinute());

        $this->holdService->expireHeldPastDeadline($this->tenant->id);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(0, $slot->usedCapacity());
    }

    public function test_dst_transition_slot_generation_preserves_local_wall_clock_time(): void
    {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SVC-DST', 'name' => 'Service', 'slug' => 'svc-dst', 'product_type' => 'booking', 'status' => 'active']);
        $bookingService = BookingServiceModel::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'duration_minutes' => 60, 'timezone' => 'Europe/Zurich', 'buffer_minutes' => 0]);
        $resource = BookingResource::create(['tenant_id' => $this->tenant->id, 'name' => 'Studio', 'capacity' => 1]);
        $resource->eligibleServices()->attach($bookingService->id);

        // Europe/Zurich DST spring-forward in 2027 is 2027-03-28.
        AvailabilityRule::create([
            'tenant_id' => $this->tenant->id,
            'booking_resource_id' => $resource->id,
            'weekday' => CarbonImmutable::parse('2027-03-28')->dayOfWeek,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'timezone' => 'Europe/Zurich',
        ]);
        AvailabilityRule::create([
            'tenant_id' => $this->tenant->id,
            'booking_resource_id' => $resource->id,
            'weekday' => CarbonImmutable::parse('2027-03-21')->dayOfWeek,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'timezone' => 'Europe/Zurich',
        ]);

        app(BookingSlotGenerationService::class)->generateForServiceResource(
            $bookingService,
            $resource,
            CarbonImmutable::parse('2027-03-21'),
            CarbonImmutable::parse('2027-03-28')
        );

        $beforeDst = BookingSlot::whereDate('starts_at', '2027-03-21')->first();
        $afterDst = BookingSlot::whereDate('starts_at', '2027-03-28')->first();

        $this->assertNotNull($beforeDst);
        $this->assertNotNull($afterDst);

        // Same LOCAL wall-clock time (10:00 Europe/Zurich) on both sides of
        // the DST transition — the stored UTC instants must differ by the
        // DST offset shift, never stay fixed.
        $this->assertSame('10:00', $beforeDst->starts_at->setTimezone('Europe/Zurich')->format('H:i'));
        $this->assertSame('10:00', $afterDst->starts_at->setTimezone('Europe/Zurich')->format('H:i'));
        $this->assertNotSame($beforeDst->starts_at->format('H:i'), $afterDst->starts_at->format('H:i'));
    }
}
