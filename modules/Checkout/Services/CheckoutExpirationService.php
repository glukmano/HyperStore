<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Illuminate\Support\Facades\DB;
use Modules\Checkout\Exceptions\CheckoutExpiredException;
use Modules\Checkout\Models\CheckoutSession;

class CheckoutExpirationService
{
    /**
     * Test-only synchronization hook invoked immediately AFTER successful preflight check
     * and BEFORE acquiring the mutation row lock.
     *
     * @var (callable(CheckoutSession): void)|null
     */
    public static mixed $afterPreflightHook = null;

    public function __construct(
        private readonly CheckoutInventoryReservationOrchestrator $reservationOrchestrator,
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
            if (self::$afterPreflightHook !== null) {
                (self::$afterPreflightHook)($session);
            }

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

        throw new CheckoutExpiredException("CHECKOUT_EXPIRED: Checkout session [{$session->id}] has expired at [{$session->expires_at->toIso8601String()}].");
    }
}
