<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Illuminate\Support\Facades\DB;
use Modules\Checkout\Contracts\CheckoutMutationBarrierInterface;
use Modules\Checkout\Exceptions\CheckoutExpiredException;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Promotions\Contracts\LoyaltyCheckoutRedemptionServiceInterface;
use Throwable;

class CheckoutExpirationService
{
    public function __construct(
        private readonly CheckoutInventoryReservationOrchestrator $reservationOrchestrator,
        private readonly CheckoutMutationBarrierInterface $mutationBarrier,
    ) {}

    /**
     * Checks if the checkout session has expired.
     * If expired, transitions state to 'expired' and releases held reservations in an isolated committed transaction.
     *
     * @throws CheckoutExpiredException
     */
    public function expireIfNeeded(CheckoutSession $session): void
    {
        if (! $session->expires_at->isPast()) {
            $this->mutationBarrier->preflightPassed($session);

            return;
        }

        // Run expiration transition in a dedicated atomic transaction
        DB::transaction(function () use ($session): void {
            /** @var CheckoutSession|null $locked */
            $locked = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            if (! in_array($locked->state, ['expired', 'cancelled', 'ready_for_order'], true)) {
                if ($locked->reservation_references !== null) {
                    $this->reservationOrchestrator->releaseAll($locked);
                    $locked->reservation_references = null;
                }

                $locked->state = 'expired';
                $locked->save();
            }
        });

        $session->refresh();

        // Non-blocking, optional cross-module dependency (same pattern as
        // Affiliate's attribution-freeze hook in OrderCreationService): a
        // checkout that expires without ever becoming an Order must not
        // leave a dangling Loyalty point deduction behind.
        if (app()->bound(LoyaltyCheckoutRedemptionServiceInterface::class)) {
            try {
                app(LoyaltyCheckoutRedemptionServiceInterface::class)->cancelForCheckout($session->uuid, $session->tenant_id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        throw new CheckoutExpiredException("CHECKOUT_EXPIRED: Checkout session [{$session->id}] has expired at [{$session->expires_at->toIso8601String()}].");
    }
}
