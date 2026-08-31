<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $parent_product_id
 * @property int $item_product_id
 * @property ?int $item_variant_id
 * @property int $quantity
 * @property bool $is_optional
 * @property int $sort_order
 */
class ProductBundleItem extends Model
{
    protected $fillable = [
        'parent_product_id',
        'item_product_id',
        'item_variant_id',
        'quantity',
        'is_optional',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_product_id' => 'integer',
            'item_product_id' => 'integer',
            'item_variant_id' => 'integer',
            'quantity' => 'integer',
            'is_optional' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function itemProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_product_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function itemVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_variant_id');
    }
}
