<?php

declare(strict_types=1);

namespace Modules\Auctions\Console\Commands;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Auctions\Services\AuctionLifecycleService;

class ProcessAuctionLifecycleCommand extends Command
{
    protected $signature = 'auctions:process-lifecycle';

    protected $description = 'Activates scheduled Auctions, closes ended Auctions, and cancels unpaid-winner Auctions past their payment window.';

    public function handle(AuctionLifecycleService $service): int
    {
        $activated = 0;
        $closed = 0;
        $cancelled = 0;

        foreach (Tenant::pluck('id') as $tenantId) {
            $activated += $service->activateScheduled((int) $tenantId);
            $closed += $service->closeEnded((int) $tenantId);
            $cancelled += $service->cancelUnpaidWinners((int) $tenantId);
        }

        $this->info("Activated [{$activated}], closed [{$closed}], cancelled-unpaid [{$cancelled}] Auction(s).");

        return self::SUCCESS;
    }
}
