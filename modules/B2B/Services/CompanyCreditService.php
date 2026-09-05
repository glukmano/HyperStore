<?php

declare(strict_types=1);

namespace Modules\B2B\Services;

use Illuminate\Support\Facades\DB;
use Modules\B2B\Contracts\CompanyCreditServiceInterface;
use Modules\B2B\Enums\CompanyCreditEntryType;
use Modules\B2B\Exceptions\B2BException;
use Modules\B2B\Exceptions\InsufficientCompanyCreditException;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyCreditAccount;
use Modules\B2B\Models\CompanyCreditAccountLock;
use Modules\B2B\Models\CompanyCreditEntry;

/**
 * Owner Delta §1: pure append-only delta model — outstanding exposure is
 * always `SUM(amount_minor)` over every entry, never a mutable balance
 * column. Owner Delta §3: concurrency-safe via a dedicated lock-anchor row
 * (`CompanyCreditAccountLock`), identical shape to
 * `Modules\Promotions\Services\LoyaltyService`'s proven redemption lock.
 */
final class CompanyCreditService implements CompanyCreditServiceInterface
{
    private function account(Company $company, string $currency): CompanyCreditAccount
    {
        /** @var CompanyCreditAccount $account */
        $account = CompanyCreditAccount::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('currency', $currency)
            ->firstOrFail();

        return $account;
    }

    private function outstandingExposureMinor(int $companyCreditAccountId): int
    {
        return (int) CompanyCreditEntry::where('company_credit_account_id', $companyCreditAccountId)->sum('amount_minor');
    }

    public function reserveForOrder(Company $company, int $amountMinor, string $currency, string $orderUuid): void
    {
        $account = $this->account($company, $currency);

        // Owner Delta §3: the lockForUpdate() row-lock only serializes
        // concurrent callers when held inside an explicit transaction —
        // without one, Postgres releases it the instant this single query
        // completes, providing zero real exclusion.
        DB::transaction(function () use ($account, $amountMinor, $currency, $orderUuid): void {
            /** @var CompanyCreditAccountLock $lock */
            $lock = CompanyCreditAccountLock::firstOrCreate([
                'tenant_id' => $account->tenant_id,
                'company_credit_account_id' => $account->id,
            ]);
            CompanyCreditAccountLock::where('id', $lock->id)->lockForUpdate()->first();

            $existing = CompanyCreditEntry::where('tenant_id', $account->tenant_id)
                ->where('source_type', 'order')
                ->where('source_uuid', $orderUuid)
                ->where('entry_type', CompanyCreditEntryType::Reservation->value)
                ->first();
            if ($existing !== null) {
                return;
            }

            $outstanding = $this->outstandingExposureMinor($account->id);
            $available = $account->approved_limit_minor - $outstanding;

            if ($amountMinor > $available) {
                throw InsufficientCompanyCreditException::forRequest($amountMinor, max(0, $available), $currency);
            }

            CompanyCreditEntry::create([
                'tenant_id' => $account->tenant_id,
                'company_credit_account_id' => $account->id,
                'entry_type' => CompanyCreditEntryType::Reservation,
                'amount_minor' => $amountMinor,
                'source_type' => 'order',
                'source_uuid' => $orderUuid,
            ]);
        });
    }

    public function releaseReservation(int $tenantId, string $orderUuid): void
    {
        $this->resolveReservation($tenantId, $orderUuid, CompanyCreditEntryType::Release);
    }

    public function settleReservation(int $tenantId, string $orderUuid): void
    {
        $this->resolveReservation($tenantId, $orderUuid, CompanyCreditEntryType::Settlement);
    }

    private function resolveReservation(int $tenantId, string $orderUuid, CompanyCreditEntryType $resolutionType): void
    {
        $reservation = CompanyCreditEntry::where('tenant_id', $tenantId)
            ->where('source_type', 'order')
            ->where('source_uuid', $orderUuid)
            ->where('entry_type', CompanyCreditEntryType::Reservation->value)
            ->first();

        if ($reservation === null) {
            return;
        }

        DB::transaction(function () use ($reservation, $orderUuid, $resolutionType): void {
            $account = CompanyCreditAccount::where('id', $reservation->company_credit_account_id)->firstOrFail();

            /** @var CompanyCreditAccountLock $lock */
            $lock = CompanyCreditAccountLock::firstOrCreate([
                'tenant_id' => $account->tenant_id,
                'company_credit_account_id' => $account->id,
            ]);
            CompanyCreditAccountLock::where('id', $lock->id)->lockForUpdate()->first();

            // Owner Delta §1: a reservation is resolved EXACTLY ONCE, by
            // either release or settlement — never both. This check plus
            // the DB partial-unique index on reverses_entry_id are the two
            // enforcement layers (app-level first, DB-level backstop). A
            // retry of the SAME resolution type is an idempotent no-op; a
            // request for the OPPOSITE resolution type is rejected outright
            // (e.g. attempting to release an already-settled reservation,
            // or vice versa).
            $existingResolution = CompanyCreditEntry::where('reverses_entry_id', $reservation->id)->first();
            if ($existingResolution !== null) {
                if ($existingResolution->entry_type === $resolutionType) {
                    return;
                }

                throw new B2BException(
                    "Reservation for Order [{$orderUuid}] was already resolved via [{$existingResolution->entry_type->value}]; cannot also resolve via [{$resolutionType->value}]."
                );
            }

            // The resolving amount ALWAYS matches the reservation exactly
            // (Owner Delta §1 — Phase-20 does not support partial
            // settlement) — never accepted from a caller.
            CompanyCreditEntry::create([
                'tenant_id' => $account->tenant_id,
                'company_credit_account_id' => $account->id,
                'entry_type' => $resolutionType,
                'amount_minor' => -$reservation->amount_minor,
                'source_type' => 'order',
                'source_uuid' => $orderUuid,
                'reverses_entry_id' => $reservation->id,
            ]);
        });
    }

    public function getAvailableCreditMinor(Company $company, string $currency): int
    {
        $account = $this->account($company, $currency);

        return max(0, $account->approved_limit_minor - $this->outstandingExposureMinor($account->id));
    }
}
