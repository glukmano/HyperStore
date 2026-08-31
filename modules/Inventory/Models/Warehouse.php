<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use BelongsToTenant;

    protected $table = 'warehouses';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
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

    public function inventorySources(): HasMany
    {
        return $this->hasMany(InventorySource::class);
    }
}
