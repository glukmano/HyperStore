<?php

declare(strict_types=1);

namespace Modules\Checkout\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Checkout\Services\CheckoutInventoryReservationOrchestrator;

class CleanupExpiredCheckoutsCommand extends Command
{
    protected $signature = 'hyper:checkout:cleanup-expired';

    protected $description = 'Expire timed-out checkout sessions and release held inventory reservations';

    public function handle(CheckoutInventoryReservationOrchestrator $reservationOrchestrator): int
    {
        $expiredSessions = CheckoutSession::query()
            ->whereNotIn('state', ['ready_for_order', 'expired', 'cancelled', 'failed'])
            ->where('expires_at', '<=', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expiredSessions as $session) {
            /** @var CheckoutSession $session */
            DB::transaction(function () use ($session, $reservationOrchestrator, &$count) {
                $session->refresh();
                if ($session->isTerminal()) {
                    return;
                }

                $reservationOrchestrator->releaseAll($session);
                $session->state = 'expired';
                $session->version++;
                $session->save();
                $count++;
            });
        }

        $this->info("Expired [{$count}] stale checkout session(s) and released held reservations.");

        return 0;
    }
}
