<?php

declare(strict_types=1);

namespace Modules\Order\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\Order;

/**
 * @property Order $resource
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'market_id' => $order->market_id,
            'channel_id' => $order->channel_id,
            'user_id' => $order->user_id,
            'is_guest' => $order->isGuest(),
            'currency' => $order->currency,
            'locale' => $order->locale,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'totals' => [
                'merchandise_subtotal_minor' => $order->merchandise_subtotal_minor,
                'discount_total_minor' => $order->discount_total_minor,
                'shipping_total_minor' => $order->shipping_total_minor,
                'tax_total_minor' => $order->tax_total_minor,
                'grand_total_minor' => $order->grand_total_minor,
            ],
            'customer' => $order->customer_snapshot,
            'shipping_address' => $order->shipping_address_snapshot,
            'billing_address' => $order->billing_address_snapshot,
            'pricing_snapshot' => $order->pricing_snapshot,
            'tax_snapshot' => $order->tax_snapshot,
            'promotion_snapshot' => $order->promotion_snapshot,
            'shipping_snapshot' => $order->shipping_snapshot,
            'fulfillment_snapshot' => $order->fulfillment_snapshot,
            'items' => OrderItemResource::collection($order->items),
            'placed_at' => $order->placed_at->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'created_at' => $order->created_at->toIso8601String(),
            'updated_at' => $order->updated_at->toIso8601String(),
        ];
    }
}
