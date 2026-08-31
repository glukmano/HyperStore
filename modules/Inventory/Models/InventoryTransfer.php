<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransfer extends Model
{
    public static function boot(): void
    {
        parent::boot();

        static::saving(function (InventoryTransfer $transfer) {
            $srcWh = $transfer->sourceWarehouse;
            if ($srcWh instanceof Warehouse && (int) $srcWh->tenant_id !== (int) $transfer->tenant_id) {
                throw new \InvalidArgumentException('Transfer tenant_id does not match Source Warehouse tenant_id.');
            }
            $destWh = $transfer->destinationWarehouse;
            if ($destWh instanceof Warehouse && (int) $destWh->tenant_id !== (int) $transfer->tenant_id) {
                throw new \InvalidArgumentException('Transfer tenant_id does not match Destination Warehouse tenant_id.');
            }
        });
    }

    use BelongsToTenant;

    protected $table = 'inventory_transfers';

    protected $fillable = [
        'tenant_id',
        'transfer_number',
        'source_warehouse_id',
        'destination_warehouse_id',
        'status',
        'dispatched_at',
        'received_at',
        'cancelled_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

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
