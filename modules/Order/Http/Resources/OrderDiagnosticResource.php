<?php

declare(strict_types=1);

namespace Modules\Order\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\Order;

/**
 * @property Order $resource
 */
class OrderDiagnosticResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        $maskedEmail = null;
        $maskedName = null;
        if (is_array($order->customer_snapshot)) {
            if (! empty($order->customer_snapshot['email'])) {
                $email = (string) $order->customer_snapshot['email'];
                $parts = explode('@', $email);
                $maskedEmail = (substr($parts[0], 0, 1) ?: '*').'***@'.($parts[1] ?? 'masked.com');
            }
            if (! empty($order->customer_snapshot['first_name']) || ! empty($order->customer_snapshot['last_name'])) {
                $f = (string) ($order->customer_snapshot['first_name'] ?? '');
                $l = (string) ($order->customer_snapshot['last_name'] ?? '');
                $maskedName = (substr($f, 0, 1) ?: '*').'*** '.(substr($l, 0, 1) ?: '*').'***';
            }
        }

        $resCount = 0;
        if (is_array($order->reservation_references)) {
            $resCount = count($order->reservation_references);
        }

        return [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'market_id' => $order->market_id,
            'channel_id' => $order->channel_id,
            'user_id' => $order->user_id,
            'checkout_id' => $order->checkout_id,
            'currency' => $order->currency,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'grand_total_minor' => $order->grand_total_minor,
            'version' => $order->version,
            'reservation_count' => $resCount,
            'customer_snippet' => [
                'masked_email' => $maskedEmail,
                'masked_name' => $maskedName,
            ],
            'item_count' => $order->items()->count(),
            'placed_at' => $order->placed_at->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'created_at' => $order->created_at->toIso8601String(),
            'updated_at' => $order->updated_at->toIso8601String(),
        ];
    }
}
