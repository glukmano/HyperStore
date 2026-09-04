<?php

declare(strict_types=1);

namespace Modules\Customers\Listeners;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\PriceDropSubscription;
use Modules\Customers\Notifications\PriceDropDetected;
use Modules\Pricing\Events\PriceChanged;

final class CheckPriceDropSubscriptions implements ShouldQueue
{
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
            if (! $subscription->shouldTrigger($event->newAmountMinor)) {
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
            $user?->notify(new PriceDropDetected($product, $event->newAmountMinor, $event->currency));
        }
    }
}
