<?php

declare(strict_types=1);

namespace Modules\Affiliate\Contracts;

use App\Core\Payables\Contracts\PayableBalanceProviderInterface;
use Carbon\CarbonImmutable;
use Modules\Affiliate\DTOs\AffiliateBalanceDTO;
use Modules\Affiliate\Models\AffiliatePayableEntry;

/**
 * Mirrors Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface
 * exactly (Owner Delta correction §1: same shape, separate table).
 */
interface AffiliatePayableSubledgerServiceInterface extends PayableBalanceProviderInterface
{
    public function accrueEarning(
        int $tenantId,
        int $affiliateId,
        ?int $affiliateConversionItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor
    ): ?AffiliatePayableEntry;

    public function accrueRefundAdjustment(
        int $tenantId,
        int $affiliateId,
        ?int $affiliateConversionItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor
    ): ?AffiliatePayableEntry;

    public function recordManualAdjustment(
        int $tenantId,
        int $affiliateId,
        string $type,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        string $reason
    ): AffiliatePayableEntry;

    public function maturePendingPayables(int $tenantId, ?CarbonImmutable $asOf = null): int;

    public function holdEntry(int $entryId, string $reason): AffiliatePayableEntry;

    public function releaseHold(int $entryId): AffiliatePayableEntry;

    public function getBalances(int $tenantId, int $affiliateId, string $currency): AffiliateBalanceDTO;

    public function getSourceRemainingAllocatableMinor(int $payableEntryId): int;
}
