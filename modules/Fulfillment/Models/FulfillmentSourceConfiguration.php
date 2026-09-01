<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Modules\Inventory\Models\InventorySource;

class FulfillmentSourceConfiguration extends Model
{
    use BelongsToTenant;

    public static function boot(): void
    {
        parent::boot();

        static::saving(function (FulfillmentSourceConfiguration $cfg) {
            $src = $cfg->inventorySource;
            if ($src instanceof InventorySource && (int) $src->tenant_id !== (int) $cfg->tenant_id) {
                throw new InvalidArgumentException("FulfillmentSourceConfiguration tenant_id [{$cfg->tenant_id}] does not match InventorySource tenant_id [{$src->tenant_id}].");
            }
        });
    }

    public $timestamps = false;

    protected $table = 'fulfillment_source_configurations';

    protected $fillable = [
        'tenant_id',
        'inventory_source_id',
        'fulfillment_mode',
        'is_active',
        'priority',
        'lead_time_days',
        'cutoff_time',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'lead_time_days' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function inventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class, 'inventory_source_id');
    }
}
