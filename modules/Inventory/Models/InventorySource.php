<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySource extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_sources';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'source_type',
        'code',
        'name',
        'status',
        'priority',
        'external_reference',
        'last_synced_at',
        'stale_after_minutes',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'integer',
        'stale_after_minutes' => 'integer',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'inventory_source_store_assignments');
    }

    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'inventory_source_market_assignments');
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'inventory_source_channel_assignments');
    }

    public function isStale(): bool
    {
        if ($this->stale_after_minutes === null || $this->last_synced_at === null) {
            return false;
        }

        return $this->last_synced_at->addMinutes($this->stale_after_minutes)->isPast();
    }
}
