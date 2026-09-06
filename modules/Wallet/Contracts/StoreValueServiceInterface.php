<?php

declare(strict_types=1);

namespace Modules\Wallet\Contracts;

use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Models\StoreValueEntry;

interface StoreValueServiceInterface
{
    /**
     * Finds an existing account for the given owner/instrument/currency, or
     * creates one. A Gift Card's account is always created directly via
     * GiftCardService (never through this generic finder), since it must
     * exist independently of any customer_profile_id (Owner Delta §15).
     */
    public function findOrCreateAccount(
        int $tenantId,
        ?int $customerProfileId,
        StoreValueInstrumentType $instrumentType,
        string $currency
    ): StoreValueAccount;

    /**
     * Always a derived value: SUM(amount_minor) over every entry for the
     * account — never a cached/mutable balance column.
     */
    public function getAvailableBalanceMinor(StoreValueAccount $account): int;

    /**
     * A real economic event: cash-funded (source_type='order') or manual
     * (source_type='manual_adjustment' — actually manual issuance uses
     * manualAdjustmentCredit() below, not issue() — issue() is reserved for
     * cash-funded issuance, e.g. a Gift Card sale or Wallet top-up Order).
     * Posts atomically with a balanced JournalEntry (ADR-0150).
     */
    public function issue(StoreValueAccount $account, int $amountMinor, string $sourceType, string $sourceUuid): StoreValueEntry;

    /**
     * Applies a Checkout-time hold — a reservation, NOT a final spend
     * (Owner Delta §16). Idempotent by (source_type='checkout_session',
     * source_uuid, entry_type='hold'). Never posts to Ledger.
     */
    public function placeHold(StoreValueAccount $account, int $amountMinor, string $sourceUuid): StoreValueEntry;

    /**
     * Converts a hold into final spend on Order/payment success. Posts
     * atomically with a balanced JournalEntry (ADR-0150).
     */
    public function captureHold(StoreValueEntry $holdEntry, string $sourceType, string $sourceUuid): StoreValueEntry;

    /**
     * Releases an unused hold (checkout cancelled/expired/payment failed).
     * Never posts to Ledger — nets the hold back to zero.
     */
    public function releaseHold(StoreValueEntry $holdEntry, string $sourceUuid): StoreValueEntry;

    /**
     * Credits the account as a refund destination (an Order refund issued
     * as Store Credit/Wallet credit, or a tender-allocation refund
     * restoring a prior capture). Posts atomically with a balanced
     * JournalEntry.
     */
    public function refundCredit(StoreValueAccount $account, int $amountMinor, string $sourceUuid): StoreValueEntry;

    /**
     * Permanently forfeits an unused balance (breakage). Posts atomically
     * with a balanced JournalEntry.
     */
    public function expire(StoreValueAccount $account, int $amountMinor, string $sourceUuid): StoreValueEntry;

    /**
     * CS/Control-Center manual issuance with no real cash backing it. Posts
     * atomically with a balanced JournalEntry.
     */
    public function manualAdjustmentCredit(StoreValueAccount $account, int $amountMinor, string $sourceUuid, ?string $reason = null): StoreValueEntry;

    /**
     * CS/Control-Center manual clawback. Posts atomically with a balanced
     * JournalEntry.
     */
    public function manualAdjustmentDebit(StoreValueAccount $account, int $amountMinor, string $sourceUuid, ?string $reason = null): StoreValueEntry;
}
