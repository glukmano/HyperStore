<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use Illuminate\Support\Facades\DB;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\Enums\AffiliateConversionStatus;
use Modules\Affiliate\Models\AffiliateConversion;

/**
 * Owner Delta correction §5: refunds/chargebacks/cancellations/manual
 * re-attribution reverse commission via idempotent, append-only compensating
 * entries — the original earning entry is never edited or deleted.
 */
final class AffiliateConversionReversalService
{
    public function __construct(
        private readonly AffiliatePayableSubledgerServiceInterface $subledgerService,
    ) {}

    /**
     * @param  string  $reasonTag  a stable, idempotency-relevant tag identifying
     *                             WHY this reversal happened (e.g. a PaymentTransaction
     *                             uuid for a refund, or 'manual_reattribution')
     * @param  float  $ratio  the fraction of each item's commission to reverse
     *                        (1.0 for a full reversal; refunds pass a partial ratio)
     */
    public function reverseConversion(AffiliateConversion $conversion, string $reasonTag, float $ratio = 1.0): void
    {
        if ($conversion->status !== AffiliateConversionStatus::Accrued) {
            // Nothing was ever accrued (still pending, or already fully
            // reversed) — there is nothing to compensate.
            return;
        }

        DB::transaction(function () use ($conversion, $reasonTag, $ratio): void {
            $items = $conversion->items()->get();

            foreach ($items as $item) {
                $reversedAmount = (int) round($item->commission_amount_minor * $ratio);
                $reversedBase = (int) round($item->commissionable_base_minor * $ratio);
                if ($reversedAmount <= 0 && $reversedBase <= 0) {
                    continue;
                }

                $sourceUuid = "affiliate_conversion_item:{$item->id}:reversal:{$reasonTag}";

                $this->subledgerService->accrueRefundAdjustment(
                    tenantId: (int) $conversion->tenant_id,
                    affiliateId: (int) $conversion->affiliate_id,
                    affiliateConversionItemId: (int) $item->id,
                    sourceType: 'affiliate_conversion_item_reversal',
                    sourceUuid: $sourceUuid,
                    currency: $item->currency,
                    amountMinor: max(1, $reversedBase),
                    commissionMinor: $reversedAmount,
                );
            }

            if ($ratio >= 1.0) {
                $conversion->status = AffiliateConversionStatus::Reversed;
                $conversion->save();
            }
        });
    }
}
