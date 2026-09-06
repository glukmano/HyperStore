<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use Illuminate\Support\Facades\DB;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Modules\Wallet\Enums\StoreValueEntryType;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Exceptions\InsufficientStoreValueBalanceException;
use Modules\Wallet\Exceptions\StoreValueCurrencyMismatchException;
use Modules\Wallet\Exceptions\StoreValueResolutionException;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Models\StoreValueAccountLock;
use Modules\Wallet\Models\StoreValueEntry;

/**
 * Owner Delta §16/§17: hold->capture/release lifecycle, atomic domain+Ledger
 * posting sharing one idempotency identity. Mirrors CompanyCreditService's
 * proven lock-anchor-inside-DB::transaction concurrency pattern exactly —
 * the lockForUpdate() row-lock only serializes concurrent callers when held
 * inside an explicit transaction.
 */
final class StoreValueService implements StoreValueServiceInterface
{
    public function __construct(
        private readonly StoreValueLedgerPostingService $ledgerPosting
    ) {}

    public function findOrCreateAccount(
        int $tenantId,
        ?int $customerProfileId,
        StoreValueInstrumentType $instrumentType,
        string $currency
    ): StoreValueAccount {
        return StoreValueAccount::firstOrCreate([
            'tenant_id' => $tenantId,
            'customer_profile_id' => $customerProfileId,
            'instrument_type' => $instrumentType->value,
            'currency' => strtoupper($currency),
        ], [
            'scope' => 'tenant',
            'status' => 'active',
        ]);
    }

    public function getAvailableBalanceMinor(StoreValueAccount $account): int
    {
        return (int) StoreValueEntry::where('store_value_account_id', $account->id)->sum('amount_minor');
    }

    private function lock(StoreValueAccount $account): void
    {
        /** @var StoreValueAccountLock $lock */
        $lock = StoreValueAccountLock::firstOrCreate([
            'tenant_id' => $account->tenant_id,
            'store_value_account_id' => $account->id,
        ]);
        StoreValueAccountLock::where('id', $lock->id)->lockForUpdate()->first();
    }

    private function assertCurrency(StoreValueAccount $account, string $currency): void
    {
        if (strtoupper($account->currency) !== strtoupper($currency)) {
            throw StoreValueCurrencyMismatchException::forAccount((int) $account->id, $account->currency, $currency);
        }
    }

    private function existingEntry(int $tenantId, string $sourceType, string $sourceUuid, StoreValueEntryType $entryType): ?StoreValueEntry
    {
        return StoreValueEntry::where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_uuid', $sourceUuid)
            ->where('entry_type', $entryType->value)
            ->first();
    }

    public function issue(StoreValueAccount $account, int $amountMinor, string $sourceType, string $sourceUuid): StoreValueEntry
    {
        $this->assertCurrency($account, $account->currency);

        return DB::transaction(function () use ($account, $amountMinor, $sourceType, $sourceUuid): StoreValueEntry {
            $this->lock($account);

            $existing = $this->existingEntry($account->tenant_id, $sourceType, $sourceUuid, StoreValueEntryType::Issue);
            if ($existing !== null) {
                return $existing;
            }

            $entry = StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::Issue,
                'amount_minor' => $amountMinor,
                'currency' => $account->currency,
                'source_type' => $sourceType,
                'source_uuid' => $sourceUuid,
            ]);

            $this->ledgerPosting->post($entry, $amountMinor, cashFunded: true);

            return $entry;
        });
    }

    public function placeHold(StoreValueAccount $account, int $amountMinor, string $sourceUuid): StoreValueEntry
    {
        return DB::transaction(function () use ($account, $amountMinor, $sourceUuid): StoreValueEntry {
            $this->lock($account);

            $existing = $this->existingEntry($account->tenant_id, 'checkout_session', $sourceUuid, StoreValueEntryType::Hold);
            if ($existing !== null) {
                return $existing;
            }

            $available = $this->getAvailableBalanceMinor($account);
            if ($amountMinor > $available) {
                throw InsufficientStoreValueBalanceException::forAccount((int) $account->id, $amountMinor, max(0, $available));
            }

            return StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::Hold,
                'amount_minor' => -$amountMinor,
                'currency' => $account->currency,
                'source_type' => 'checkout_session',
                'source_uuid' => $sourceUuid,
            ]);
        });
    }

    public function captureHold(StoreValueEntry $holdEntry, string $sourceType, string $sourceUuid): StoreValueEntry
    {
        return DB::transaction(function () use ($holdEntry, $sourceType, $sourceUuid): StoreValueEntry {
            /** @var StoreValueAccount $account */
            $account = StoreValueAccount::where('id', $holdEntry->store_value_account_id)->firstOrFail();
            $this->lock($account);

            $resolved = $this->resolveOrThrow($holdEntry, StoreValueEntryType::Capture);
            if ($resolved !== null) {
                return $resolved;
            }

            $amountMinor = abs($holdEntry->amount_minor);

            $entry = StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::Capture,
                'amount_minor' => 0,
                'currency' => $account->currency,
                'source_type' => $sourceType,
                'source_uuid' => $sourceUuid,
                'reverses_entry_id' => $holdEntry->id,
            ]);

            $this->ledgerPosting->post($entry, $amountMinor);

            return $entry;
        });
    }

    public function releaseHold(StoreValueEntry $holdEntry, string $sourceUuid): StoreValueEntry
    {
        return DB::transaction(function () use ($holdEntry, $sourceUuid): StoreValueEntry {
            /** @var StoreValueAccount $account */
            $account = StoreValueAccount::where('id', $holdEntry->store_value_account_id)->firstOrFail();
            $this->lock($account);

            $resolved = $this->resolveOrThrow($holdEntry, StoreValueEntryType::Release);
            if ($resolved !== null) {
                return $resolved;
            }

            return StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::Release,
                'amount_minor' => abs($holdEntry->amount_minor),
                'currency' => $account->currency,
                'source_type' => 'checkout_session',
                'source_uuid' => $sourceUuid,
                'reverses_entry_id' => $holdEntry->id,
            ]);
        });
    }

    /**
     * Owner Delta §16 (mirroring B2B's §1 discipline exactly): a hold is
     * resolved EXACTLY ONCE, by either capture OR release, never both. A
     * retry of the SAME resolution type is an idempotent no-op (returns the
     * existing entry); a request for the OPPOSITE resolution type is
     * rejected outright.
     */
    private function resolveOrThrow(StoreValueEntry $holdEntry, StoreValueEntryType $resolutionType): ?StoreValueEntry
    {
        $existing = StoreValueEntry::where('reverses_entry_id', $holdEntry->id)->first();
        if ($existing === null) {
            return null;
        }

        if ($existing->entry_type === $resolutionType) {
            return $existing;
        }

        throw StoreValueResolutionException::alreadyResolved((int) $holdEntry->id);
    }

    public function refundCredit(StoreValueAccount $account, int $amountMinor, string $sourceUuid): StoreValueEntry
    {
        return DB::transaction(function () use ($account, $amountMinor, $sourceUuid): StoreValueEntry {
            $this->lock($account);

            $existing = $this->existingEntry($account->tenant_id, 'order_refund', $sourceUuid, StoreValueEntryType::RefundCredit);
            if ($existing !== null) {
                return $existing;
            }

            $entry = StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::RefundCredit,
                'amount_minor' => $amountMinor,
                'currency' => $account->currency,
                'source_type' => 'order_refund',
                'source_uuid' => $sourceUuid,
            ]);

            $this->ledgerPosting->post($entry, $amountMinor);

            return $entry;
        });
    }

    public function expire(StoreValueAccount $account, int $amountMinor, string $sourceUuid): StoreValueEntry
    {
        return DB::transaction(function () use ($account, $amountMinor, $sourceUuid): StoreValueEntry {
            $this->lock($account);

            $existing = $this->existingEntry($account->tenant_id, 'expiration', $sourceUuid, StoreValueEntryType::Expire);
            if ($existing !== null) {
                return $existing;
            }

            $available = $this->getAvailableBalanceMinor($account);
            if ($amountMinor > $available) {
                throw InsufficientStoreValueBalanceException::forAccount((int) $account->id, $amountMinor, max(0, $available));
            }

            $entry = StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::Expire,
                'amount_minor' => -$amountMinor,
                'currency' => $account->currency,
                'source_type' => 'expiration',
                'source_uuid' => $sourceUuid,
            ]);

            $this->ledgerPosting->post($entry, $amountMinor);

            return $entry;
        });
    }

    public function manualAdjustmentCredit(StoreValueAccount $account, int $amountMinor, string $sourceUuid, ?string $reason = null): StoreValueEntry
    {
        return DB::transaction(function () use ($account, $amountMinor, $sourceUuid): StoreValueEntry {
            $this->lock($account);

            $existing = $this->existingEntry($account->tenant_id, 'manual_adjustment', $sourceUuid, StoreValueEntryType::ManualAdjustmentCredit);
            if ($existing !== null) {
                return $existing;
            }

            $entry = StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::ManualAdjustmentCredit,
                'amount_minor' => $amountMinor,
                'currency' => $account->currency,
                'source_type' => 'manual_adjustment',
                'source_uuid' => $sourceUuid,
            ]);

            $this->ledgerPosting->post($entry, $amountMinor);

            return $entry;
        });
    }

    public function manualAdjustmentDebit(StoreValueAccount $account, int $amountMinor, string $sourceUuid, ?string $reason = null): StoreValueEntry
    {
        return DB::transaction(function () use ($account, $amountMinor, $sourceUuid): StoreValueEntry {
            $this->lock($account);

            $existing = $this->existingEntry($account->tenant_id, 'manual_adjustment', $sourceUuid, StoreValueEntryType::ManualAdjustmentDebit);
            if ($existing !== null) {
                return $existing;
            }

            $available = $this->getAvailableBalanceMinor($account);
            if ($amountMinor > $available) {
                throw InsufficientStoreValueBalanceException::forAccount((int) $account->id, $amountMinor, max(0, $available));
            }

            $entry = StoreValueEntry::create([
                'tenant_id' => $account->tenant_id,
                'store_value_account_id' => $account->id,
                'instrument_type' => $account->instrument_type,
                'entry_type' => StoreValueEntryType::ManualAdjustmentDebit,
                'amount_minor' => -$amountMinor,
                'currency' => $account->currency,
                'source_type' => 'manual_adjustment',
                'source_uuid' => $sourceUuid,
            ]);

            $this->ledgerPosting->post($entry, $amountMinor);

            return $entry;
        });
    }
}
