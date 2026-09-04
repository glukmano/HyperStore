<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\SellerOrderStatus;
use Modules\Order\Models\OrderItem;

/**
 * Derives verified-purchase eligibility exclusively from real Order/OrderItem/
 * SellerOrder data — never a client-supplied boolean. Computed once at review
 * submission time and stored as a snapshot on the review row (OrderItem is
 * itself an immutable snapshot, so re-querying on every render is wasted
 * work, not extra correctness).
 */
final class VerifiedPurchaseResolver
{
    /**
     * A product review is verified when the reviewer has at least one
     * OrderItem for this product (and variant, if given) belonging to an
     * Order they placed that has reached OrderStatus::COMPLETED.
     */
    public function isVerifiedForProduct(int $tenantId, int $userId, int $productId, ?int $variantId = null): bool
    {
        return OrderItem::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->whereHas('order', fn ($q) => $q
                ->where('user_id', $userId)
                ->where('order_status', OrderStatus::COMPLETED->value))
            ->exists();
    }

    /**
     * A vendor review is verified when the reviewer has at least one
     * OrderItem attributed (by snapshot) to this vendor. Completion is
     * checked per the vendor-split SellerOrder when one exists for that
     * line (marketplace order), falling back to the parent Order's own
     * completion status for non-marketplace tenant-direct sales.
     */
    public function isVerifiedForVendor(int $tenantId, int $userId, int $vendorId): bool
    {
        $orderItems = OrderItem::query()
            ->where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId))
            ->with(['order', 'sellerOrderItem.sellerOrder'])
            ->get();

        foreach ($orderItems as $orderItem) {
            $sellerOrder = $orderItem->sellerOrderItem?->sellerOrder;

            if ($sellerOrder !== null) {
                if ($sellerOrder->status === SellerOrderStatus::COMPLETED->value) {
                    return true;
                }

                continue;
            }

            if ($orderItem->order?->order_status === OrderStatus::COMPLETED->value) {
                return true;
            }
        }

        return false;
    }
}
