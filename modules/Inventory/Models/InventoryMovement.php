<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

class InventoryMovement extends Model
{
    use BelongsToTenant;

    public const array VALID_TYPES = [
        'receive',
        'adjustment_in',
        'adjustment_out',
        'reservation_commit',
        'transfer_out',
        'transfer_in',
        'damaged',
        'quarantine_in',
        'quarantine_out',
        'correction',
        'recount',
    ];

    public $timestamps = false;

    protected $table = 'inventory_movements';

    protected $fillable = [
        'tenant_id',
        'stock_item_id',
        'inventory_source_id',
        'product_id',
        'product_variant_id',
        'quantity_delta',
        'resulting_on_hand',
        'movement_type',
        'reference_type',
        'reference_id',
        'actor_type',
        'actor_id',
        'causation_id',
        'idempotency_key',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:4',
        'resulting_on_hand' => 'decimal:4',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new LogicException('Inventory movements are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('Inventory movements are immutable and cannot be deleted.');
        });
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function inventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
