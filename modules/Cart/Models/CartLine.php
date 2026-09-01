<?php

declare(strict_types=1);

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $quantity
 * @property int|null $display_unit_price_minor
 * @property string|null $display_currency
 * @property string $signature
 * @property array<string, mixed>|null $options
 * @property array<string, mixed>|null $customizations
 * @property bool $is_price_stale
 * @property array<string, mixed>|null $metadata
 * @property-read Cart $cart
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class CartLine extends Model
{
    protected $table = 'cart_lines';

    protected $fillable = [
        'cart_id',
        'product_id',
        'variant_id',
        'quantity',
        'display_unit_price_minor',
        'display_currency',
        'signature',
        'options',
        'customizations',
        'metadata',
    ];

    protected $casts = [
        'display_unit_price_minor' => 'integer',
        'options' => 'array',
        'customizations' => 'array',
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
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

    public function getQuantityVO(): CartQuantity
    {
        return CartQuantity::fromString((string) $this->quantity);
    }
}
