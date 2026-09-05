<?php

declare(strict_types=1);

namespace App\Core\Payables\Contracts;

/**
 * Minimal, beneficiary-agnostic balance query surface AbstractPayoutOrchestrator
 * depends on. A beneficiary-specific subledger service (VendorPayableSubledgerService,
 * AffiliatePayableSubledgerService, ...) implements this in addition to its own
 * richer domain interface — purely additive, no change to existing methods.
 */
interface PayableBalanceProviderInterface
{
    public function getWithdrawableBalanceMinor(int $tenantId, int $beneficiaryId, string $currency): int;

    public function getSourceRemainingAllocatableMinor(int $payableEntryId): int;
}
