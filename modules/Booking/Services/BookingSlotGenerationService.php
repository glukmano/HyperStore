<?php

declare(strict_types=1);

namespace Modules\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Booking\Models\BookingResource;
use Modules\Booking\Models\BookingService;
use Modules\Booking\Models\BookingSlot;

/**
 * Owner Delta §4/§16: generates `BookingSlot` rows for one (Service,
 * Resource) pair from the Resource's own recurring `AvailabilityRule`s,
 * expressed in the Resource's own IANA timezone — DST transitions are
 * handled by Carbon's timezone-aware arithmetic here, never a raw stored
 * UTC offset. Owner Delta §8: idempotent under the DB unique constraint —
 * a repeated run for an already-generated period is a safe no-op.
 */
final class BookingSlotGenerationService
{
    public function generateForServiceResource(BookingService $service, BookingResource $resource, CarbonImmutable $fromDate, CarbonImmutable $toDate): int
    {
        $rules = $resource->availabilityRules()->get()->groupBy('weekday');
        $exceptions = $resource->availabilityExceptions()
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->get()
            ->groupBy('date');

        $count = 0;
        $cursor = $fromDate->startOfDay();

        while ($cursor->lte($toDate)) {
            $dateStr = $cursor->toDateString();
            $dayExceptions = $exceptions->get($dateStr, collect());

            if ($dayExceptions->contains(fn ($e) => $e->type === 'closure')) {
                $cursor = $cursor->addDay();

                continue;
            }

            $windows = $rules->get((int) $cursor->dayOfWeek, collect())
                ->map(fn ($rule) => ['start' => $rule->start_time, 'end' => $rule->end_time, 'timezone' => $rule->timezone]);

            foreach ($dayExceptions->where('type', 'extra') as $extra) {
                $windows->push(['start' => $extra->start_time, 'end' => $extra->end_time, 'timezone' => $resource->availabilityRules()->value('timezone') ?? 'UTC']);
            }

            foreach ($windows as $window) {
                $count += $this->generateSlotsWithinWindow($service, $resource, $dateStr, $window);
            }

            $cursor = $cursor->addDay();
        }

        return $count;
    }

    /**
     * @param  array{start: string, end: string, timezone: string}  $window
     */
    private function generateSlotsWithinWindow(BookingService $service, BookingResource $resource, string $dateStr, array $window): int
    {
        $tz = $window['timezone'];
        $slotStart = CarbonImmutable::parse("{$dateStr} {$window['start']}", $tz);
        $windowEnd = CarbonImmutable::parse("{$dateStr} {$window['end']}", $tz);
        $stepMinutes = $service->duration_minutes + $service->buffer_minutes;

        $count = 0;
        while ($slotStart->addMinutes($service->duration_minutes)->lte($windowEnd)) {
            $slotEnd = $slotStart->addMinutes($service->duration_minutes);

            DB::table('booking_slots')->insertOrIgnore([
                'tenant_id' => $resource->tenant_id,
                'booking_resource_id' => $resource->id,
                'booking_service_id' => $service->id,
                'starts_at' => $slotStart->utc(),
                'ends_at' => $slotEnd->utc(),
                'capacity' => $resource->capacity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;

            $slotStart = $slotStart->addMinutes($stepMinutes);
        }

        return $count;
    }
}
