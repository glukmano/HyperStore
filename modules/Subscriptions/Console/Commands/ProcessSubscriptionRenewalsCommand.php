<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Console\Commands;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Subscriptions\Services\SubscriptionRenewalService;

class ProcessSubscriptionRenewalsCommand extends Command
{
    protected $signature = 'subscriptions:process-renewals';

    protected $description = 'Claims and processes every due Subscription renewal and dunning retry across all active tenants.';

    public function handle(SubscriptionRenewalService $renewalService): int
    {
        $tenants = Tenant::where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            $renewalService->processDueRenewals((int) $tenant->id);
        }

        $this->info("Processed Subscription renewals for {$tenants->count()} active tenant(s).");

        return self::SUCCESS;
    }
}
