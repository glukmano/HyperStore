<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Inventory\ValueObjects\Quantity;

class StockItem extends Model
{
    public static function boot(): void
    {
        parent::boot();

        static::saving(function (StockItem $item) {
            $prod = $item->product;
            if ($prod instanceof Product && (int) $prod->tenant_id !== (int) $item->tenant_id) {
                throw new \InvalidArgumentException("StockItem tenant_id [{$item->tenant_id}] does not match Product tenant_id [{$prod->tenant_id}].");
            }
            $src = $item->inventorySource;
            if ($src instanceof InventorySource && (int) $src->tenant_id !== (int) $item->tenant_id) {
                throw new \InvalidArgumentException("StockItem tenant_id [{$item->tenant_id}] does not match InventorySource tenant_id [{$src->tenant_id}].");
            }
        });
    }

    use BelongsToTenant;

    protected $table = 'stock_items';

    protected $fillable = [
        'tenant_id',
        'inventory_source_id',
        'product_id',
        'product_variant_id',
        'on_hand',
        'reserved',
        'quarantined',
        'damaged',
        'incoming',
        'low_stock_threshold',
        'backorder_mode',
        'backorder_limit',
        'tracking_mode',
        'unit_of_measure_code',
        'metadata',
    ];

    protected $casts = [
        'on_hand' => 'decimal:4',
        'reserved' => 'decimal:4',
        'quarantined' => 'decimal:4',
        'damaged' => 'decimal:4',
        'incoming' => 'decimal:4',
        'low_stock_threshold' => 'decimal:4',
        'backorder_limit' => 'decimal:4',
        'metadata' => 'array',
    ];

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

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function getAvailableToSellQuantity(): Quantity
    {
        if ($this->tracking_mode === 'untracked') {
            return Quantity::fromString('9999999.0000');
        }

        $onHand = Quantity::fromString((string) $this->on_hand);
        $reserved = Quantity::fromString((string) $this->reserved);
        $quarantined = Quantity::fromString((string) $this->quarantined);
        $damaged = Quantity::fromString((string) $this->damaged);

        $unavailable = $reserved->add($quarantined)->add($damaged);
        if ($unavailable->isGreaterThan($onHand)) {
            return Quantity::zero();
        }

        return $onHand->subtract($unavailable);
    }
}
