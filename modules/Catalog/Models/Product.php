<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Modules\Catalog\Contracts\ProductTypeInterface;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $tenant_id
 * @property ?int $brand_id
 * @property ?int $attribute_set_id
 * @property string $product_type
 * @property string $sku
 * @property ?string $barcode
 * @property ?string $mpn
 * @property string $status
 * @property ?array<string, mixed> $metadata
 */
class Product extends Model implements HasMedia
{
    use BelongsToTenant, InteractsWithMedia, Searchable;

    protected $fillable = [
        'tenant_id',
        'brand_id',
        'attribute_set_id',
        'product_type',
        'sku',
        'barcode',
        'mpn',
        'status',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'brand_id' => 'integer',
            'attribute_set_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function getTypeDefinition(): ProductTypeInterface
    {
        return app(ProductTypeRegistryInterface::class)->get($this->product_type);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * @return BelongsTo<AttributeSet, $this>
     */
    public function attributeSet(): BelongsTo
    {
        return $this->belongsTo(AttributeSet::class, 'attribute_set_id');
    }

    /**
     * @return HasMany<ProductTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class, 'product_id');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    /**
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'product_id');
    }

    /**
     * @return HasMany<ProductAttributeOption, $this>
     */
    public function attributeOptions(): HasMany
    {
        return $this->hasMany(ProductAttributeOption::class, 'product_id');
    }

    /**
     * @return HasMany<ProductCustomField, $this>
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(ProductCustomField::class, 'product_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<ProductBundleItem, $this>
     */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'parent_product_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<ProductRelationship, $this>
     */
    public function relationships(): HasMany
    {
        return $this->hasMany(ProductRelationship::class, 'product_id');
    }

    /**
     * @return HasMany<ProductStoreListing, $this>
     */
    public function storeListings(): HasMany
    {
        return $this->hasMany(ProductStoreListing::class, 'product_id');
    }

    /**
     * @return BelongsToMany<Store, $this>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'product_store_listings')
            ->withPivot('status', 'visibility', 'is_featured', 'sort_order', 'published_at')
            ->withTimestamps();
    }

    public function translation(?string $locale = null): ?ProductTranslation
    {
        $locale = $locale ?? app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale', 'en'))
            ?? $this->translations->first();
    }

    public function getNameAttribute(): string
    {
        $trans = $this->translation();

        return $trans !== null ? $trans->name : (string) $this->sku;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('product_thumbnail')->singleFile();
        $this->addMediaCollection('product_gallery');
    }

    /**
     * Only ever searchable when at least one store listing is published +
     * visible — an unpublished/draft product is never written to the
     * index at all (belt-and-suspenders with query-time filtering, per
     * ADR/Master §26: search index is not source of truth).
     */
    public function shouldBeSearchable(): bool
    {
        return $this->storeListings()->where('status', 'published')->where('visibility', 'visible')->exists();
    }

    public function searchableAs(): string
    {
        return 'products';
    }

    /**
     * One document per product carries every locale's translated content
     * as locale-suffixed fields (name_en, name_ar, ...) rather than one
     * index per locale — a pragmatic Phase-17 simplification that still
     * supports genuinely multilingual full-text search without Scout's
     * single-index-per-model constraint forcing N indices for N locales.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $document = [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sku' => $this->sku,
            'product_type' => $this->product_type,
            'brand_id' => $this->brand_id,
            'category_ids' => $this->categories()->pluck('categories.id')->all(),
            'store_ids' => $this->storeListings()->where('status', 'published')->where('visibility', 'visible')->pluck('store_id')->all(),
            'is_featured' => $this->storeListings()->where('is_featured', true)->exists(),
        ];

        foreach ($this->translations as $translation) {
            $document['name_'.$translation->locale] = $translation->name;
            $document['description_'.$translation->locale] = $translation->short_description;
        }

        return $document;
    }
}
