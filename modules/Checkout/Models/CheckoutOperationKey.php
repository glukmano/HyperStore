<?php

declare(strict_types=1);

namespace Modules\Checkout\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cart\Models\Cart;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $cart_id
 * @property int|null $checkout_session_id
 * @property string $operation_type
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property string $status
 * @property array<string, mixed>|null $response_payload
 * @property array<string, mixed>|null $error_payload
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CheckoutOperationKey extends Model
{
    protected $table = 'checkout_operation_keys';

    protected $fillable = [
        'tenant_id',
        'cart_id',
        'checkout_session_id',
        'operation_type',
        'idempotency_key',
        'request_fingerprint',
        'status',
        'response_payload',
        'error_payload',
        'lease_expires_at',
        'completed_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'error_payload' => 'array',
        'lease_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    /**
     * @return BelongsTo<CheckoutSession, $this>
     */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }
}
