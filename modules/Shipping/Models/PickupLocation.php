<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\Warehouse;

class PickupLocation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'pickup_locations';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'inventory_source_id',
        'warehouse_id',
        'fee_amount',
        'currency',
        'instructions',
        'status',
        'created_at',
    ];

    protected $casts = [
        'fee_amount' => 'integer',
        'created_at' => 'datetime',
    ];

    public function inventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class, 'inventory_source_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
