<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Modules\Marketplace\Models\Vendor;

class Warehouse extends Model
{
    use BelongsToTenant;

    protected $table = 'warehouses';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'ownership_type',
        'vendor_id',
        'status',
        'country_code',
        'state_code',
        'city',
        'postal_code',
        'address_line_1',
        'address_line_2',
        'latitude',
        'longitude',
        'timezone',
        'priority',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'priority' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'metadata' => 'array',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::saving(function (Warehouse $warehouse) {
            if ($warehouse->ownership_type === 'vendor' && $warehouse->vendor_id === null) {
                throw new InvalidArgumentException('Warehouse ownership_type "vendor" requires vendor_id to be set.');
            }
            if ($warehouse->ownership_type !== 'vendor' && $warehouse->vendor_id !== null) {
                throw new InvalidArgumentException('Warehouse vendor_id may only be set when ownership_type is "vendor".');
            }
            $vendor = $warehouse->vendor;
            if ($vendor instanceof Vendor && (int) $vendor->tenant_id !== (int) $warehouse->tenant_id) {
                throw new InvalidArgumentException("Warehouse tenant_id [{$warehouse->tenant_id}] does not match Vendor tenant_id [{$vendor->tenant_id}].");
            }
        });
    }

    public function inventorySources(): HasMany
    {
        return $this->hasMany(InventorySource::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
