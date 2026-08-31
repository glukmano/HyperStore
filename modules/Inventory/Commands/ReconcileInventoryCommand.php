<?php

declare(strict_types=1);

namespace Modules\Inventory\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Services\InventoryReconciliationService;

class ReconcileInventoryCommand extends Command
{
    protected $signature = 'inventory:reconcile {--tenant=1}';

    protected $description = 'Audit and detect discrepancies between stock balances, movement ledgers, and reservations';

    public function handle(InventoryReconciliationService $reconciliationService): int
    {
        $tenantId = (int) $this->option('tenant');
        $report = $reconciliationService->reconcile($tenantId);

        if ($report['is_clean']) {
            $this->info("Inventory reconciliation clean. Total items checked: [{$report['total_stock_items']}].");

            return self::SUCCESS;
        }

        $this->warn('Inventory discrepancies detected!');
        $this->table(['Stock Item ID', 'On Hand', 'Expected On Hand', 'Drift'], $report['balance_discrepancies']);
        $this->table(['Stock Item ID', 'Reserved', 'Expected Reserved', 'Drift'], $report['reservation_discrepancies']);

        return self::FAILURE;
    }
}
