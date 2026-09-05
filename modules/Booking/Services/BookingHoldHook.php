<?php

declare(strict_types=1);

namespace Modules\Booking\Services;

use App\Models\User;
use Modules\Booking\Contracts\BookingHoldHookInterface;
use Modules\Booking\Models\BookingSlot;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Customers\Services\CustomerProfileService;

final class BookingHoldHook implements BookingHoldHookInterface
{
    public function __construct(
        private readonly BookingHoldService $holdService,
        private readonly CustomerProfileService $profileService,
    ) {}

    public function createHoldsForCheckout(CheckoutSession $session): void
    {
        $session->loadMissing('cart.lines');

        // Cheap, side-effect-free short-circuit BEFORE resolving any
        // Customer identity — the overwhelming majority of Checkouts carry
        // no Booking line at all, and must not pay the cost (or risk, in a
        // Tenant-context-less test/edge-case scenario) of a
        // CustomerProfile lookup for no reason.
        $hasBookingLine = $session->cart->lines->contains(
            fn ($l) => is_int($l->metadata['booking_slot_id'] ?? null) || is_string($l->metadata['booking_slot_id'] ?? null)
        );
        if (! $hasBookingLine) {
            return;
        }

        $user = $session->user_id !== null ? User::find($session->user_id) : null;
        if ($user === null) {
            // Owner Delta: Booking requires an identifiable Customer
            // (holds/entitlements are not modeled for anonymous guests in
            // this phase).
            return;
        }

        $profile = $this->profileService->firstOrCreateFor($user);

        foreach ($session->cart->lines as $line) {
            $slotId = $line->metadata['booking_slot_id'] ?? null;
            if (! is_int($slotId) && ! is_string($slotId)) {
                continue;
            }

            $slot = BookingSlot::find((int) $slotId);
            if ($slot === null) {
                continue;
            }

            $booking = $this->holdService->hold(
                $slot,
                $profile,
                (int) $slot->booking_service_id,
                (string) $session->uuid,
                (string) $session->expires_at,
            );

            // Denormalize the resolved Booking snapshot onto the CartLine's
            // own metadata (mirroring Auction's pattern exactly) — so
            // OrderCreationService's OrderItem loop can freeze it without
            // Order needing any hard dependency on Booking's own models.
            $line->update(['metadata' => array_merge($line->metadata ?? [], [
                'booking_id' => $booking->id,
                'booking_slot_starts_at_snapshot' => $slot->starts_at->toIso8601String(),
                'booking_timezone_snapshot' => $slot->service?->timezone,
            ])]);
        }
    }

    public function cancelHoldsForCheckout(CheckoutSession $session): void
    {
        $this->holdService->cancelHeldForCheckoutSession((string) $session->uuid);
    }
}
