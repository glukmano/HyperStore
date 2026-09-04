<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryOperationKey extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'inventory_operation_keys';

    protected $fillable = [
        'tenant_id',
        'idempotency_key',
        'payload_hash',
        'operation_type',
        'resource_type',
        'resource_id',
        'status',
        'response_payload',
        'error_message',
        'lease_expires_at',
        'created_at',
        'completed_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'lease_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
