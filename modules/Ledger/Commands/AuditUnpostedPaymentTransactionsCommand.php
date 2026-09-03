<?php

declare(strict_types=1);

namespace Modules\Ledger\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Policies\PaymentMovementEligibilityPolicy;

class AuditUnpostedPaymentTransactionsCommand extends Command
{
    protected $signature = 'ledger:audit-unposted-payment-transactions
                            {--tenant= : Filter by tenant ID}
                            {--from= : Filter by start date (Y-m-d)}';

    protected $description = 'Audit successful payment transactions to detect missing financial ledger journals (Read-Only)';

    public function handle(PaymentMovementEligibilityPolicy $policy): int
    {
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $fromDate = $this->option('from');

        $query = DB::table('payment_transactions')
            ->where('status', 'success');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($fromDate !== null) {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $fromDate)->startOfDay());
        }

        $transactions = $query->get();
        $unposted = [];

        foreach ($transactions as $tx) {
            if (! $policy->isEligible((string) $tx->operation_type, (string) $tx->status, (int) $tx->amount_minor)) {
                continue;
            }

            $postingType = $policy->resolvePostingType((string) $tx->operation_type);

            $exists = DB::table('journal_entries')
                ->where('tenant_id', $tx->tenant_id)
                ->where('source_module', 'payment')
                ->where('source_type', 'payment_transaction')
                ->where('source_uuid', $tx->uuid)
                ->where('posting_type', $postingType)
                ->exists();

            if (! $exists) {
                $unposted[] = [
                    'tenant_id' => $tx->tenant_id,
                    'transaction_uuid' => $tx->uuid,
                    'operation_type' => $tx->operation_type,
                    'amount_minor' => $tx->amount_minor,
                    'currency' => $tx->currency,
                    'occurred_at' => $tx->updated_at,
                ];
            }
        }

        if (empty($unposted)) {
            $this->info('Audit clean: All eligible payment transactions have corresponding ledger journals.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d unposted eligible payment transaction(s):', count($unposted)));
        $this->table(['Tenant ID', 'Transaction UUID', 'Operation', 'Amount Minor', 'Currency', 'Occurred At'], $unposted);

        return self::FAILURE;
    }
}
