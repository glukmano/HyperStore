<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\InventorySource;

class FulfillmentSourceConfiguration extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'fulfillment_source_configurations';

    protected $fillable = [
        'tenant_id',
        'inventory_source_id',
        'fulfillment_mode',
        'priority',
        'status',
        'created_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'created_at' => 'datetime',
    ];

    public function inventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class, 'inventory_source_id');
    }
}
