<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\Warehouse;

class PickupLocation extends Model
{
    use BelongsToTenant;

    public static function boot(): void
    {
        parent::boot();

        static::saving(function (PickupLocation $loc) {
            $src = $loc->inventorySource;
            if ($src instanceof InventorySource && (int) $src->tenant_id !== (int) $loc->tenant_id) {
                throw new InvalidArgumentException("PickupLocation tenant_id [{$loc->tenant_id}] does not match InventorySource tenant_id [{$src->tenant_id}].");
            }
            $wh = $loc->warehouse;
            if ($wh instanceof Warehouse && (int) $wh->tenant_id !== (int) $loc->tenant_id) {
                throw new InvalidArgumentException("PickupLocation tenant_id [{$loc->tenant_id}] does not match Warehouse tenant_id [{$wh->tenant_id}].");
            }
        });
    }

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
