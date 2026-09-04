<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

/**
 * @property int $id
 * @property int $registry_id
 * @property int $product_id
 * @property ?int $variant_id
 * @property int $quantity_requested
 * @property int $quantity_purchased
 * @property string $priority
 * @property ?string $note
 */
class GiftRegistryItem extends Model
{
    protected $fillable = [
        'registry_id',
        'product_id',
        'variant_id',
        'quantity_requested',
        'quantity_purchased',
        'priority',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registry_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'quantity_requested' => 'integer',
            'quantity_purchased' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GiftRegistry, $this>
     */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(GiftRegistry::class, 'registry_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * @return HasMany<GiftRegistryPurchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(GiftRegistryPurchase::class, 'registry_item_id');
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->quantity_requested - $this->quantity_purchased);
    }

    public function isFullyPurchased(): bool
    {
        return $this->remainingQuantity() === 0;
    }
}
