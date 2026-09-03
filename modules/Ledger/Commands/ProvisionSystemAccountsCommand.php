<?php

declare(strict_types=1);

namespace Modules\Ledger\Commands;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;

class ProvisionSystemAccountsCommand extends Command
{
    protected $signature = 'ledger:provision-system-accounts
                            {--tenant= : Provision system accounts for a specific tenant ID}';

    protected $description = 'Explicitly provision required system ledger accounts (payment_clearing, customer_funds_liability) for tenants';

    public function handle(LedgerAccountRegistryInterface $registry): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId !== null) {
            $tenant = Tenant::find((int) $tenantId);
            if ($tenant === null) {
                $this->error("Tenant [{$tenantId}] not found.");

                return self::FAILURE;
            }

            $registry->ensureRequiredSystemAccounts((int) $tenant->id);
            $this->info("Successfully provisioned required system accounts for tenant [{$tenant->id}].");

            return self::SUCCESS;
        }

        $tenants = Tenant::where('status', 'active')->get();
        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($tenants as $tenant) {
            $registry->ensureRequiredSystemAccounts((int) $tenant->id);
            $count++;
        }

        $this->info("Successfully provisioned required system accounts for {$count} active tenant(s).");

        return self::SUCCESS;
    }
}
