<?php

declare(strict_types=1);

namespace Modules\Customers\Listeners;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\BackInStockSubscription;
use Modules\Customers\Notifications\BackInStockDetected;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Events\StockReplenished;
use Modules\Inventory\Services\InventoryAvailabilityService;

final class CheckBackInStockSubscriptions implements ShouldQueue
{
    public function __construct(
        private readonly InventoryAvailabilityService $availabilityService,
    ) {}

    public function handle(StockReplenished $event): void
    {
        $candidates = BackInStockSubscription::query()
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
            // The event fires at inventory-source granularity; confirm the
            // subscription's own Store actually sees this product as
            // available now, via the real, existing eligibility-aware
            // availability service — not re-derived from the raw event.
            $availability = $this->availabilityService->check(
                $event->productId,
                $event->variantId,
                new InventoryContext(tenantId: $event->tenantId, storeId: $subscription->store_id),
            );

            if (! $availability->isInStock) {
                continue;
            }

            $claimed = DB::table('back_in_stock_subscriptions')
                ->where('id', $subscription->id)
                ->whereNull('notified_at')
                ->update(['notified_at' => now(), 'is_active' => false]);

            if ($claimed !== 1) {
                continue;
            }

            $user = User::query()->find($subscription->user_id);
            $user?->notify(new BackInStockDetected($product));
        }
    }
}
