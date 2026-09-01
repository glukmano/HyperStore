<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Modules\Inventory\Models\InventorySource;

class ShippingSourceMethodMapping extends Model
{
    use BelongsToTenant;

    public static function boot(): void
    {
        parent::boot();

        static::saving(function (ShippingSourceMethodMapping $map) {
            $src = $map->inventorySource;
            if ($src instanceof InventorySource && (int) $src->tenant_id !== (int) $map->tenant_id) {
                throw new InvalidArgumentException("ShippingSourceMethodMapping tenant_id [{$map->tenant_id}] does not match InventorySource tenant_id [{$src->tenant_id}].");
            }
            $method = $map->shippingMethod;
            if ($method instanceof ShippingMethod && (int) $method->tenant_id !== (int) $map->tenant_id) {
                throw new InvalidArgumentException("ShippingSourceMethodMapping tenant_id [{$map->tenant_id}] does not match ShippingMethod tenant_id [{$method->tenant_id}].");
            }
        });
    }

    public $timestamps = false;

    protected $table = 'shipping_source_method_mappings';

    protected $fillable = [
        'tenant_id',
        'inventory_source_id',
        'shipping_method_id',
        'is_allowed',
        'created_at',
    ];

    protected $casts = [
        'is_allowed' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function inventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class, 'inventory_source_id');
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }
}
