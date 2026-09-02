<?php

declare(strict_types=1);

namespace Modules\Payment\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $idempotency_key
 * @property string $operation_type
 * @property int $order_id
 * @property int|null $payment_id
 * @property string $request_hash
 * @property array<string, mixed>|null $response_payload
 * @property array<string, mixed>|null $error_payload
 * @property string $status
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read Payment|null $payment
 */
class PaymentOperationKey extends Model
{
    use BelongsToTenant;

    protected $table = 'payment_operation_keys';

    protected $fillable = [
        'tenant_id',
        'idempotency_key',
        'operation_type',
        'order_id',
        'payment_id',
        'request_hash',
        'response_payload',
        'error_payload',
        'status',
        'lease_expires_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'order_id' => 'integer',
            'payment_id' => 'integer',
            'response_payload' => 'array',
            'error_payload' => 'array',
            'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
