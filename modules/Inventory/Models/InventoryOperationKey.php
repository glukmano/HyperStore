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
        'operation_type',
        'resource_type',
        'resource_id',
        'response_payload',
        'created_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'created_at' => 'datetime',
    ];
}
