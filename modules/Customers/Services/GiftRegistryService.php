<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\GiftRegistry;
use Modules\Customers\Models\GiftRegistryItem;
use Modules\Customers\Models\GiftRegistryPurchase;
use Modules\Order\Models\OrderItem;

final class GiftRegistryService
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    /**
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function create(User $user, string $title, string $eventType, ?string $eventDate, ?array $shippingAddress = null, ?string $message = null): GiftRegistry
    {
        return GiftRegistry::query()->create([
            'tenant_id' => (int) $this->contextManager->getTenant()->getId(),
            'user_id' => $user->id,
            'title' => $title,
            'event_type' => $eventType,
            'event_date' => $eventDate,
            'shipping_address' => $shippingAddress,
            'message' => $message,
        ]);
    }

    public function addItem(GiftRegistry $registry, int $productId, ?int $variantId, int $quantityRequested, string $priority = 'medium', ?string $note = null): GiftRegistryItem
    {
        return $registry->items()->create([
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity_requested' => $quantityRequested,
            'priority' => $priority,
            'note' => $note,
        ]);
    }

    /**
     * Records a gift purchase against a registry item, incrementing the
     * fulfilled quantity — called from a listener on OrderStatusChanged
     * (dimension=order_status, toStatus=completed), never derived from a
     * mutable counter. `$orderItemId` anchors the purchase to Order's own
     * immutable snapshot data, referenced by ID only (soft reference,
     * matching how OrderItem itself references product_id).
     */
    /**
     * `order_item_id` is uniquely constrained (one order line can only ever
     * fulfil one gift-registry purchase record), so this is safe to call
     * more than once for the same OrderItem — a duplicate OrderStatusChanged
     * delivery increments `quantity_purchased` at most once.
     */
    public function recordPurchase(GiftRegistryItem $item, int $orderId, int $orderItemId, ?int $purchaserUserId, int $quantity): GiftRegistryPurchase
    {
        return DB::transaction(function () use ($item, $orderId, $orderItemId, $purchaserUserId, $quantity): GiftRegistryPurchase {
            $existing = GiftRegistryPurchase::query()
                ->where('order_item_id', $orderItemId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $purchase = GiftRegistryPurchase::query()->create([
                'registry_item_id' => $item->id,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'purchaser_user_id' => $purchaserUserId,
                'quantity' => $quantity,
                'purchased_at' => now(),
            ]);

            $item->increment('quantity_purchased', $quantity);

            return $purchase;
        });
    }

    /**
     * A cart line only counts as a gift-registry purchase when it was
     * explicitly added with that intent — carried through checkout as cart
     * line metadata and snapshotted onto OrderItem.customization_metadata_snapshot
     * (Order's own existing column; Customers never writes to Order's tables).
     * A loose product-id match would wrongly count an unrelated purchase of
     * the same product as fulfilling someone's registry.
     */
    public function findRegistryItemForOrderItem(OrderItem $orderItem): ?GiftRegistryItem
    {
        $registryItemId = $orderItem->customization_metadata_snapshot['gift_registry_item_id'] ?? null;

        if (! is_int($registryItemId) && ! (is_string($registryItemId) && ctype_digit($registryItemId))) {
            return null;
        }

        return GiftRegistryItem::query()->find((int) $registryItemId);
    }
}
