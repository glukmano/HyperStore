<?php

declare(strict_types=1);

namespace Modules\Checkout\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Checkout\Models\CheckoutSession;

/**
 * @property CheckoutSession $resource
 */
class CheckoutDiagnosticResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        $maskedEmail = null;
        $maskedName = null;
        if (is_array($session->customer_data)) {
            if (! empty($session->customer_data['email'])) {
                $email = (string) $session->customer_data['email'];
                $parts = explode('@', $email);
                $maskedEmail = (substr($parts[0], 0, 1) ?: '*').'***@'.($parts[1] ?? 'masked.com');
            }
            if (! empty($session->customer_data['first_name']) || ! empty($session->customer_data['last_name'])) {
                $f = (string) ($session->customer_data['first_name'] ?? '');
                $l = (string) ($session->customer_data['last_name'] ?? '');
                $maskedName = (substr($f, 0, 1) ?: '*').'*** '.(substr($l, 0, 1) ?: '*').'***';
            }
        }

        $shippingStatus = 'none_required';
        if ($session->selected_shipping_quote !== null) {
            $shippingStatus = ($session->selected_shipping_quote['is_expired'] ?? false) ? 'expired' : 'selected';
        } elseif ($session->shipping_address !== null) {
            $shippingStatus = 'pending_selection';
        }

        $resCount = 0;
        if (is_array($session->reservation_references)) {
            $resCount = count($session->reservation_references);
        }

        $isStale = false;
        if ($session->cart !== null && $session->evaluated_cart_version !== null) {
            $isStale = $session->cart->version > $session->evaluated_cart_version;
        }

        return [
            'id' => $session->id,
            'uuid' => $session->uuid,
            'state' => $session->state,
            'tenant_id' => $session->tenant_id,
            'store_id' => $session->store_id,
            'market_id' => $session->market_id,
            'channel_id' => $session->channel_id,
            'currency' => $session->currency,
            'cart_id' => $session->cart_id,
            'cart_version' => $session->cart?->version,
            'evaluated_cart_version' => $session->evaluated_cart_version,
            'is_stale' => $isStale,
            'expires_at' => $session->expires_at->toIso8601String(),
            'reservation_count' => $resCount,
            'shipping_status' => $shippingStatus,
            'blocking_reason' => $session->state === 'expired' ? 'session_expired' : ($isStale ? 'cart_modified' : null),
            'customer_snippet' => [
                'masked_email' => $maskedEmail,
                'masked_name' => $maskedName,
            ],
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];
    }
}
