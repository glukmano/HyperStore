<?php

declare(strict_types=1);

namespace Modules\Customers\Listeners;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\PriceDropSubscription;
use Modules\Customers\Notifications\PriceDropDetected;
use Modules\Notifications\Services\NotificationDispatchService;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\Events\PriceChanged;

final class CheckPriceDropSubscriptions implements ShouldQueue
{
    public function __construct(
        private readonly PriceResolverInterface $priceResolver,
        private readonly NotificationDispatchService $notificationDispatch,
    ) {}

    public function handle(PriceChanged $event): void
    {
        $candidates = PriceDropSubscription::query()
            ->where('tenant_id', $event->tenantId)
            ->where('product_id', $event->productId)
            ->where('variant_id', $event->variantId)
            ->where('is_active', true)
            ->whereNull('notified_at')
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        $product = Product::query()->find($event->productId);
        if ($product === null) {
            return;
        }

        foreach ($candidates as $subscription) {
            /** @var PriceDropSubscription $subscription */

            // Customers never calculates a price itself: the subscription's
            // own stored store/channel/market/currency context is re-resolved
            // through Pricing's authoritative PriceResolverInterface right
            // now — the PriceChanged event payload is only the trigger to
            // re-check, never the trusted source of the current price.
            $currentPrice = $this->priceResolver->resolve(
                new PricingItem(productId: $event->productId, variantId: $event->variantId),
                new PricingContext(
                    tenantId: $event->tenantId,
                    currency: $subscription->currency,
                    storeId: $subscription->store_id,
                    marketId: $subscription->market_id,
                    channelId: $subscription->channel_id,
                ),
            );

            if ($currentPrice === null) {
                continue;
            }

            $currentAmountMinor = $currentPrice->unitPrice->getMinorAmount();

            if (! $subscription->shouldTrigger($currentAmountMinor)) {
                continue;
            }

            // Atomic conditional claim: only the request that actually flips
            // notified_at from NULL sends the notification — dedupes
            // concurrent PriceChanged deliveries without a heavier
            // claim-row/idempotency-key mechanism (that pattern is reserved
            // for financial/checkout-grade replay safety).
            $claimed = DB::table('price_drop_subscriptions')
                ->where('id', $subscription->id)
                ->whereNull('notified_at')
                ->update(['notified_at' => now(), 'is_active' => false]);

            if ($claimed !== 1) {
                continue;
            }

            $user = User::query()->find($subscription->user_id);
            if ($user !== null) {
                $this->notificationDispatch->send($user, 'price_drop', new PriceDropDetected($product, $currentAmountMinor, $subscription->currency));
            }
        }
    }
}
