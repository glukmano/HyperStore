<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payment\Models\PaymentTransaction;

/**
 * @mixin PaymentTransaction
 */
class PaymentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation_type' => $this->operation_type,
            'status' => $this->status,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'provider_code' => $this->provider_code,
            'payment_method_type' => $this->payment_method_type,
            'provider_reference' => $this->provider_reference,
            'normalized_error_code' => $this->normalized_error_code,
            'action_type' => $this->action_type,
            'action_payload' => $this->action_payload,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
