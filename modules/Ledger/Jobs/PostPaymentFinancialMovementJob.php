<?php

declare(strict_types=1);

namespace Modules\Ledger\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\DTOs\PaymentFinancialMovementDTO;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Policies\PaymentMovementEligibilityPolicy;

class PostPaymentFinancialMovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public PaymentFinancialMovementDTO $movement
    ) {}

    public function handle(
        LedgerAccountRegistryInterface $accountRegistry,
        LedgerPostingServiceInterface $postingService,
        PaymentMovementEligibilityPolicy $eligibilityPolicy
    ): void {
        if (! $eligibilityPolicy->isEligible($this->movement->operationType, 'success', $this->movement->amountMinor)) {
            return;
        }

        $postingType = $eligibilityPolicy->resolvePostingType($this->movement->operationType);

        // Fail-closed resolution of required system accounts (no implicit provisioning during posting)
        $paymentClearing = $accountRegistry->getAccountByRole(
            $this->movement->tenantId,
            SystemAccountRole::PAYMENT_CLEARING
        );

        $customerFunds = $accountRegistry->getAccountByRole(
            $this->movement->tenantId,
            SystemAccountRole::CUSTOMER_FUNDS_LIABILITY
        );

        if ($postingType === 'capture') {
            // Purchase / Capture movement:
            // Debit: payment_clearing
            // Credit: customer_funds_liability
            $lines = [
                new JournalLineDTO(
                    accountId: (int) $paymentClearing->id,
                    direction: JournalDirection::DEBIT,
                    amountMinor: $this->movement->amountMinor,
                    currency: $this->movement->currency,
                    description: "Payment clearing for transaction [{$this->movement->transactionUuid}]"
                ),
                new JournalLineDTO(
                    accountId: (int) $customerFunds->id,
                    direction: JournalDirection::CREDIT,
                    amountMinor: $this->movement->amountMinor,
                    currency: $this->movement->currency,
                    description: "Customer funds liability for transaction [{$this->movement->transactionUuid}]"
                ),
            ];
        } else {
            // Refund movement:
            // Debit: customer_funds_liability
            // Credit: payment_clearing
            $lines = [
                new JournalLineDTO(
                    accountId: (int) $customerFunds->id,
                    direction: JournalDirection::DEBIT,
                    amountMinor: $this->movement->amountMinor,
                    currency: $this->movement->currency,
                    description: "Customer funds liability reduction for refund [{$this->movement->transactionUuid}]"
                ),
                new JournalLineDTO(
                    accountId: (int) $paymentClearing->id,
                    direction: JournalDirection::CREDIT,
                    amountMinor: $this->movement->amountMinor,
                    currency: $this->movement->currency,
                    description: "Payment clearing disbursement for refund [{$this->movement->transactionUuid}]"
                ),
            ];
        }

        $draft = new JournalDraftDTO(
            tenantId: $this->movement->tenantId,
            sourceModule: 'payment',
            sourceType: 'payment_transaction',
            sourceUuid: $this->movement->transactionUuid,
            postingType: $postingType,
            currency: $this->movement->currency,
            description: "Payment movement [{$postingType}] for transaction [{$this->movement->transactionUuid}]",
            effectiveAt: $this->movement->occurredAtUtc,
            postedAt: CarbonImmutable::now('UTC'),
            lines: $lines,
            metadata: [
                'payment_uuid' => $this->movement->paymentUuid,
                'order_uuid' => $this->movement->orderUuid,
                'operation_type' => $this->movement->operationType,
            ]
        );

        $postingService->post($draft);
    }
}
