<?php

declare(strict_types=1);

namespace Modules\Ledger\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\PaymentFinancialMovementDTO;
use Modules\Ledger\Jobs\PostPaymentFinancialMovementJob;
use Modules\Ledger\Policies\PaymentMovementEligibilityPolicy;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;

class ReplayUnpostedPaymentTransactionsCommand extends Command
{
    protected $signature = 'ledger:replay-unposted-payment-transactions
                            {--tenant= : Filter by tenant ID}
                            {--from= : Filter by start date (Y-m-d)}
                            {--dry-run : Simulate replay without writing ledger journals}';

    protected $description = 'Replay unposted eligible payment transactions through standard LedgerPostingService';

    public function handle(PaymentMovementEligibilityPolicy $policy): int
    {
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $fromDate = $this->option('from');
        $isDryRun = (bool) $this->option('dry-run');

        $query = PaymentTransaction::where('status', 'success');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($fromDate !== null) {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $fromDate)->startOfDay());
        }

        /** @var Collection<int, PaymentTransaction> $transactions */
        $transactions = $query->with('payment.order')->get();
        $replayedCount = 0;

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

            if ($exists) {
                continue;
            }

            if ($isDryRun) {
                $this->line("[DRY RUN] Would replay transaction [{$tx->uuid}] for amount [{$tx->amount_minor} {$tx->currency}]");
                $replayedCount++;

                continue;
            }

            /** @var Payment $payment */
            $payment = $tx->payment;
            $orderUuid = $payment->order !== null ? (string) $payment->order->uuid : null;

            $dto = new PaymentFinancialMovementDTO(
                tenantId: (int) $tx->tenant_id,
                paymentUuid: (string) $payment->uuid,
                transactionUuid: (string) $tx->uuid,
                operationType: (string) $tx->operation_type,
                amountMinor: (int) $tx->amount_minor,
                currency: (string) $tx->currency,
                occurredAtUtc: CarbonImmutable::instance($tx->updated_at)->utc(),
                orderUuid: $orderUuid
            );

            // Execute job synchronously through the standard container pipeline
            app()->make(PostPaymentFinancialMovementJob::class, ['movement' => $dto])->handle(
                app(LedgerAccountRegistryInterface::class),
                app(LedgerPostingServiceInterface::class),
                $policy
            );

            $replayedCount++;
        }

        if ($isDryRun) {
            $this->info("[DRY RUN] Simulated replay for {$replayedCount} transaction(s).");
        } else {
            $this->info("Successfully replayed {$replayedCount} transaction(s) into the financial ledger.");
        }

        return self::SUCCESS;
    }
}
