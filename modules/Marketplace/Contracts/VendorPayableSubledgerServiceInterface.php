<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use App\Core\Payables\Contracts\PayableBalanceProviderInterface;
use Carbon\CarbonImmutable;
use Modules\Marketplace\DTOs\VendorBalanceDTO;
use Modules\Marketplace\Models\VendorPayableEntry;

/**
 * Extends the Core PayableBalanceProviderInterface (additive — adds
 * getWithdrawableBalanceMinor()) so this service can be passed directly into
 * AbstractPayoutOrchestrator without a second, parallel balance-query surface.
 */
interface VendorPayableSubledgerServiceInterface extends PayableBalanceProviderInterface
{
    public function accrueEarning(
        int $tenantId,
        int $vendorId,
        ?int $orderItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor,
        ?int $storeId = null
    ): ?VendorPayableEntry;

    public function accrueRefundAdjustment(
        int $tenantId,
        int $vendorId,
        ?int $orderItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor,
        ?int $storeId = null
    ): ?VendorPayableEntry;

    public function recordManualAdjustment(
        int $tenantId,
        int $vendorId,
        string $type,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        string $reason
    ): VendorPayableEntry;

    public function maturePendingPayables(int $tenantId, ?CarbonImmutable $asOf = null): int;

    public function holdEntry(int $entryId, string $reason): VendorPayableEntry;

    public function releaseHold(int $entryId): VendorPayableEntry;

    public function getBalances(int $tenantId, int $vendorId, string $currency): VendorBalanceDTO;

    public function getSourceRemainingAllocatableMinor(int $payableEntryId): int;
}
