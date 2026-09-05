<?php

declare(strict_types=1);

namespace Modules\Booking\Services;

use Illuminate\Support\Facades\DB;
use Modules\Booking\Enums\BookingStatus;
use Modules\Booking\Exceptions\SlotCapacityExceededException;
use Modules\Booking\Models\Booking;
use Modules\Booking\Models\BookingSlot;
use Modules\Customers\Models\CustomerProfile;

/**
 * Owner Delta §5/§6/§8: capacity-safe hold creation. `BookingSlot` is
 * row-locked for the whole count+create; used capacity is always a live
 * `COUNT(*)` over `bookings` (no cached counter to drift). A hold is
 * idempotent per `(booking_slot_id, checkout_session_uuid)` — the SAME
 * Checkout session retrying its own hold call gets back its existing row,
 * never a second one, and never double-counts against capacity.
 */
final class BookingHoldService
{
    public function hold(BookingSlot $slot, CustomerProfile $customer, int $bookingServiceId, string $checkoutSessionUuid, string $holdExpiresAt): Booking
    {
        return DB::transaction(function () use ($slot, $customer, $bookingServiceId, $checkoutSessionUuid, $holdExpiresAt): Booking {
            /** @var BookingSlot $lockedSlot */
            $lockedSlot = BookingSlot::where('id', $slot->id)->lockForUpdate()->firstOrFail();

            $existing = Booking::where('booking_slot_id', $lockedSlot->id)
                ->where('checkout_session_uuid', $checkoutSessionUuid)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $used = $lockedSlot->usedCapacity();
            if ($used >= $lockedSlot->capacity) {
                throw SlotCapacityExceededException::forSlot($lockedSlot->id);
            }

            return Booking::create([
                'tenant_id' => $lockedSlot->tenant_id,
                'customer_profile_id' => $customer->id,
                'booking_service_id' => $bookingServiceId,
                'booking_slot_id' => $lockedSlot->id,
                'status' => BookingStatus::Held,
                'hold_expires_at' => $holdExpiresAt,
                'checkout_session_uuid' => $checkoutSessionUuid,
            ]);
        });
    }

    public function expireHeldPastDeadline(int $tenantId): int
    {
        $count = 0;

        Booking::where('tenant_id', $tenantId)
            ->where('status', BookingStatus::Held)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->get()
            ->each(function (Booking $booking) use (&$count): void {
                DB::transaction(function () use ($booking, &$count): void {
                    /** @var Booking|null $locked */
                    $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== BookingStatus::Held) {
                        return;
                    }

                    $locked->update(['status' => BookingStatus::Cancelled]);
                    $count++;
                });
            });

        return $count;
    }

    public function cancelHeldForCheckoutSession(string $checkoutSessionUuid): void
    {
        Booking::where('checkout_session_uuid', $checkoutSessionUuid)
            ->where('status', BookingStatus::Held)
            ->update(['status' => BookingStatus::Cancelled]);
    }

    public function confirmForCheckoutSession(string $checkoutSessionUuid, int $orderId): void
    {
        Booking::where('checkout_session_uuid', $checkoutSessionUuid)
            ->where('status', BookingStatus::Held)
            ->update(['status' => BookingStatus::Confirmed, 'order_id' => $orderId]);
    }
}
