<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Modules\Customers\Models\BackInStockSubscription;
use Modules\Customers\Models\PriceDropSubscription;

final class AlertSubscriptionService
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function subscribeToPriceDrop(User $user, int $productId, ?int $variantId, int $baselinePriceMinor, string $currency, ?int $targetPriceMinor = null): PriceDropSubscription
    {
        return PriceDropSubscription::query()->updateOrCreate(
            [
                'tenant_id' => (int) $this->contextManager->getTenant()->getId(),
                'user_id' => $user->id,
                'product_id' => $productId,
                'variant_id' => $variantId,
            ],
            [
                'baseline_price_minor' => $baselinePriceMinor,
                'currency' => $currency,
                'target_price_minor' => $targetPriceMinor,
                'is_active' => true,
                'notified_at' => null,
                'created_at' => now(),
            ],
        );
    }

    public function unsubscribeFromPriceDrop(User $user, int $productId, ?int $variantId): void
    {
        PriceDropSubscription::query()
            ->where('tenant_id', (int) $this->contextManager->getTenant()->getId())
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->update(['is_active' => false]);
    }

    public function subscribeToBackInStock(User $user, int $productId, ?int $variantId, int $storeId): BackInStockSubscription
    {
        return BackInStockSubscription::query()->updateOrCreate(
            [
                'tenant_id' => (int) $this->contextManager->getTenant()->getId(),
                'user_id' => $user->id,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'store_id' => $storeId,
            ],
            [
                'is_active' => true,
                'notified_at' => null,
                'created_at' => now(),
            ],
        );
    }

    public function unsubscribeFromBackInStock(User $user, int $productId, ?int $variantId, int $storeId): void
    {
        BackInStockSubscription::query()
            ->where('tenant_id', (int) $this->contextManager->getTenant()->getId())
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('store_id', $storeId)
            ->update(['is_active' => false]);
    }
}
