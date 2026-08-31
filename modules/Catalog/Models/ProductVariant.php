<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property ?string $barcode
 * @property string $combination_hash
 * @property string $status
 * @property int $sort_order
 * @property ?array<string, mixed> $metadata
 */
class ProductVariant extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'combination_hash',
        'status',
        'sort_order',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return HasMany<ProductVariantOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductVariantOption::class, 'variant_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('variant_gallery');
    }
}
