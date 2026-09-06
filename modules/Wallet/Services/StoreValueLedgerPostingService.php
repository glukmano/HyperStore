<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use Carbon\CarbonImmutable;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Models\JournalEntry;
use Modules\Wallet\Enums\StoreValueEntryType;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Models\StoreValueEntry;

/**
 * ADR-0150: the exact posting matrix derived from a real source audit of
 * the Phase-09/Phase-10 Ledger model. `hold`/`release` never call this
 * class at all (Owner Delta §16) — only issue/capture/refund_credit/
 * expire/manual_adjustment_* are real economic events.
 */
class StoreValueLedgerPostingService
{
    public function __construct(
        private readonly LedgerAccountRegistryInterface $accountRegistry,
        private readonly LedgerPostingServiceInterface $postingService
    ) {}

    /**
     * @param  int  $amountMinor  the REAL positive amount this posting
     *                            moves — for `capture`, this is the
     *                            resolved hold's amount, since the domain
     *                            StoreValueEntry itself carries 0 (the
     *                            balance was already reduced at hold time).
     * @param  bool  $cashFunded  only meaningful for `issue` — true when
     *                            funded by a real captured Order payment
     *                            (Debit customer_funds_liability), false
     *                            for a manual/no-cash issuance (Debit
     *                            store_value_non_cash_adjustment).
     */
    public function post(StoreValueEntry $entry, int $amountMinor, bool $cashFunded = false): ?JournalEntry
    {
        if (in_array($entry->entry_type, [StoreValueEntryType::Hold, StoreValueEntryType::Release], true)) {
            return null;
        }

        $instrumentRole = $this->instrumentRole($entry->instrument_type);
        $instrumentAccount = $this->accountRegistry->getAccountByRole($entry->tenant_id, $instrumentRole);

        [$debitAccount, $creditAccount] = match ($entry->entry_type) {
            StoreValueEntryType::Issue => [
                $cashFunded
                    ? $this->accountRegistry->getAccountByRole($entry->tenant_id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY)
                    : $this->accountRegistry->getAccountByRole($entry->tenant_id, SystemAccountRole::STORE_VALUE_NON_CASH_ADJUSTMENT),
                $instrumentAccount,
            ],
            StoreValueEntryType::Capture => [
                $instrumentAccount,
                $this->accountRegistry->getAccountByRole($entry->tenant_id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY),
            ],
            StoreValueEntryType::RefundCredit => [
                $this->accountRegistry->getAccountByRole($entry->tenant_id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY),
                $instrumentAccount,
            ],
            StoreValueEntryType::Expire, StoreValueEntryType::ManualAdjustmentDebit => [
                $instrumentAccount,
                $this->accountRegistry->getAccountByRole($entry->tenant_id, SystemAccountRole::STORE_VALUE_NON_CASH_ADJUSTMENT),
            ],
            StoreValueEntryType::ManualAdjustmentCredit => [
                $this->accountRegistry->getAccountByRole($entry->tenant_id, SystemAccountRole::STORE_VALUE_NON_CASH_ADJUSTMENT),
                $instrumentAccount,
            ],
        };

        return $this->postingService->post(new JournalDraftDTO(
            tenantId: $entry->tenant_id,
            sourceModule: 'wallet',
            sourceType: $entry->source_type,
            sourceUuid: $entry->source_uuid,
            postingType: $entry->entry_type->value,
            currency: $entry->currency,
            description: "Store Value [{$entry->entry_type->value}] for [{$entry->instrument_type->value}] account, source [{$entry->source_uuid}]",
            effectiveAt: CarbonImmutable::instance($entry->created_at),
            postedAt: CarbonImmutable::now('UTC'),
            lines: [
                new JournalLineDTO((int) $debitAccount->id, JournalDirection::DEBIT, $amountMinor, $entry->currency),
                new JournalLineDTO((int) $creditAccount->id, JournalDirection::CREDIT, $amountMinor, $entry->currency),
            ],
            metadata: [
                'store_value_account_id' => $entry->store_value_account_id,
                'store_value_entry_id' => $entry->id,
            ]
        ));
    }

    private function instrumentRole(StoreValueInstrumentType $type): SystemAccountRole
    {
        return match ($type) {
            StoreValueInstrumentType::Wallet => SystemAccountRole::WALLET_LIABILITY,
            StoreValueInstrumentType::StoreCredit => SystemAccountRole::STORE_CREDIT_LIABILITY,
            StoreValueInstrumentType::GiftCard => SystemAccountRole::GIFT_CARD_LIABILITY,
        };
    }
}
