<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_id
 * @property int $store_id
 * @property string $status
 * @property string $visibility
 * @property bool $is_featured
 * @property int $sort_order
 * @property ?string $published_at
 */
class ProductStoreListing extends Model
{
    protected $fillable = [
        'product_id',
        'store_id',
        'status',
        'visibility',
        'is_featured',
        'sort_order',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'store_id' => 'integer',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return HasMany<ProductStoreListingTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductStoreListingTranslation::class, 'product_store_listing_id');
    }

    /**
     * @return BelongsToMany<Market, $this>
     */
    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'product_store_markets')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'product_store_channels')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    public function translation(?string $locale = null): ?ProductStoreListingTranslation
    {
        $locale = $locale ?? app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale', 'en'))
            ?? $this->translations->first();
    }
}
