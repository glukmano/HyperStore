<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payment\Models\Payment;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'authorized_amount_minor' => $this->authorized_amount_minor,
            'captured_amount_minor' => $this->captured_amount_minor,
            'refunded_amount_minor' => $this->refunded_amount_minor,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'transactions' => PaymentTransactionResource::collection($this->whenLoaded('transactions')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
