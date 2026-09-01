<?php

declare(strict_types=1);

namespace Modules\Checkout\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Checkout\Exceptions\CheckoutExpiredException;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Checkout\Services\CheckoutExpirationService;

class CleanupExpiredCheckoutsCommand extends Command
{
    protected $signature = 'hyper:checkout:cleanup-expired';

    protected $description = 'Expire timed-out checkout sessions and release held inventory reservations';

    public function handle(CheckoutExpirationService $expirationService): int
    {
        $expiredSessions = CheckoutSession::query()
            ->whereNotIn('state', ['ready_for_order', 'expired', 'cancelled', 'failed'])
            ->where('expires_at', '<=', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expiredSessions as $session) {
            /** @var CheckoutSession $session */
            try {
                $expirationService->expireIfNeeded($session);
                $count++;
            } catch (CheckoutExpiredException) {
                $count++;
            }
        }

        $this->info("Expired [{$count}] stale checkout session(s) and released held reservations.");

        return 0;
    }
}
