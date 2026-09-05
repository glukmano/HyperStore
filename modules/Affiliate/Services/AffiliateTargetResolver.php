<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use App\Core\Stores\Models\Store;
use Modules\Affiliate\Contracts\AffiliateTargetResolverInterface;
use Modules\Affiliate\Enums\AffiliateTargetType;
use Modules\Affiliate\Exceptions\AffiliateTargetResolutionException;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Marketplace\Models\Vendor;
use Modules\Order\Models\OrderItem;

/**
 * Owner Delta correction §9: target_id is never trusted directly. Both
 * Referral Code/Campaign administration AND attribution/commission
 * evaluation go through this single resolver.
 */
final class AffiliateTargetResolver implements AffiliateTargetResolverInterface
{
    public function assertEligible(int $tenantId, AffiliateTargetType $targetType, ?int $targetId): void
    {
        if ($targetType === AffiliateTargetType::Platform) {
            if ($targetId !== null) {
                throw AffiliateTargetResolutionException::notFoundInTenant($targetType->value, $targetId, $tenantId);
            }

            return;
        }

        if ($targetId === null || ! $this->exists($tenantId, $targetType, $targetId)) {
            throw AffiliateTargetResolutionException::notFoundInTenant($targetType->value, $targetId ?? 0, $tenantId);
        }
    }

    public function orderItemMatchesTarget(int $tenantId, AffiliateTargetType $targetType, ?int $targetId, int $orderItemId): bool
    {
        if ($targetType === AffiliateTargetType::Platform) {
            return true;
        }

        /** @var OrderItem|null $item */
        $item = OrderItem::where('tenant_id', $tenantId)->find($orderItemId);
        if ($item === null) {
            return false;
        }

        return match ($targetType) {
            AffiliateTargetType::Vendor => $item->vendor_id !== null && (int) $item->vendor_id === $targetId,
            AffiliateTargetType::Product => $item->product_id !== null && (int) $item->product_id === $targetId,
            AffiliateTargetType::Category => $item->product_id !== null && $this->productInCategory($tenantId, (int) $item->product_id, (int) $targetId),
            AffiliateTargetType::Store => true, // Store scope is already implied by the Order's own store_id
        };
    }

    private function exists(int $tenantId, AffiliateTargetType $targetType, int $targetId): bool
    {
        return match ($targetType) {
            AffiliateTargetType::Store => Store::where('tenant_id', $tenantId)->where('id', $targetId)->exists(),
            AffiliateTargetType::Vendor => Vendor::where('tenant_id', $tenantId)->where('id', $targetId)->exists(),
            AffiliateTargetType::Category => Category::where('tenant_id', $tenantId)->where('id', $targetId)->exists(),
            AffiliateTargetType::Product => Product::where('tenant_id', $tenantId)->where('id', $targetId)->exists(),
            AffiliateTargetType::Platform => true,
        };
    }

    private function productInCategory(int $tenantId, int $productId, int $categoryId): bool
    {
        /** @var Product|null $product */
        $product = Product::where('tenant_id', $tenantId)->find($productId);
        if ($product === null) {
            return false;
        }

        return $product->categories()->where('categories.id', $categoryId)->exists();
    }
}
