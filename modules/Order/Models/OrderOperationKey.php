<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $idempotency_key
 * @property string $operation_type
 * @property int|null $checkout_id
 * @property int|null $order_id
 * @property string $request_hash
 * @property array<string, mixed>|null $response_payload
 * @property array<string, mixed>|null $error_payload
 * @property string $status
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrderOperationKey extends Model
{
    use BelongsToTenant;

    protected $table = 'order_operation_keys';

    protected $fillable = [
        'tenant_id',
        'idempotency_key',
        'operation_type',
        'checkout_id',
        'order_id',
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
            'checkout_id' => 'integer',
            'order_id' => 'integer',
            'response_payload' => 'array',
            'error_payload' => 'array',
            'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
