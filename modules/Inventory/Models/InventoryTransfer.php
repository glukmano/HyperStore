<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class InventoryTransfer extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_transfers';

    protected $fillable = [
        'tenant_id',
        'transfer_number',
        'source_inventory_source_id',
        'destination_inventory_source_id',
        'source_warehouse_id',
        'destination_warehouse_id',
        'status',
        'dispatched_at',
        'received_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::saving(function (InventoryTransfer $transfer) {
            $src = $transfer->sourceInventorySource;
            if ($src instanceof InventorySource && (int) $src->tenant_id !== (int) $transfer->tenant_id) {
                throw new InvalidArgumentException('Transfer tenant_id does not match Source InventorySource tenant_id.');
            }
            $dest = $transfer->destinationInventorySource;
            if ($dest instanceof InventorySource && (int) $dest->tenant_id !== (int) $transfer->tenant_id) {
                throw new InvalidArgumentException('Transfer tenant_id does not match Destination InventorySource tenant_id.');
            }
            if ((int) $transfer->source_inventory_source_id === (int) $transfer->destination_inventory_source_id) {
                throw new InvalidArgumentException('Source and Destination InventorySources must be different.');
            }
        });
    }

    public function sourceInventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class, 'source_inventory_source_id');
    }

    public function destinationInventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class, 'destination_inventory_source_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }
}
