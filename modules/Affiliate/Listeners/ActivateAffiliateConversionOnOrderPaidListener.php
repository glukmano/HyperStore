<?php

declare(strict_types=1);

namespace Modules\Affiliate\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\Enums\AffiliateConversionStatus;
use Modules\Affiliate\Models\AffiliateConversion;
use Modules\Order\Events\OrderStatusChanged;

/**
 * Owner Delta correction §2: this listener ACTIVATES an already-frozen
 * attribution — it never resolves or recomputes attribution itself. It only
 * ever reads AffiliateConversion/AffiliateConversionItem rows that were
 * written at Order-creation time by AffiliateAttributionService.
 */
final class ActivateAffiliateConversionOnOrderPaidListener
{
    public function __construct(
        private readonly AffiliatePayableSubledgerServiceInterface $subledgerService,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->dimension !== 'payment' || $event->toStatus !== 'paid') {
            return;
        }

        $order = $event->order;

        /** @var AffiliateConversion|null $conversion */
        $conversion = AffiliateConversion::where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->id)
            ->where('status', AffiliateConversionStatus::Pending->value)
            ->first();

        if ($conversion === null) {
            return;
        }

        DB::transaction(function () use ($conversion): void {
            $items = $conversion->items()->get();

            foreach ($items as $item) {
                $this->subledgerService->accrueEarning(
                    tenantId: (int) $conversion->tenant_id,
                    affiliateId: (int) $conversion->affiliate_id,
                    affiliateConversionItemId: (int) $item->id,
                    sourceType: 'affiliate_conversion_item',
                    sourceUuid: 'affiliate_conversion_item:'.$item->id,
                    currency: $item->currency,
                    amountMinor: max(1, $item->commissionable_base_minor),
                    commissionMinor: $item->commission_amount_minor,
                );
            }

            $conversion->status = AffiliateConversionStatus::Accrued;
            $conversion->save();
        });
    }
}
