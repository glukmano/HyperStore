<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_store_listing_id
 * @property string $locale
 * @property string $slug
 * @property ?string $name
 * @property ?string $short_description
 * @property ?string $description
 */
class ProductStoreListingTranslation extends Model
{
    protected $fillable = [
        'product_store_listing_id',
        'locale',
        'slug',
        'name',
        'short_description',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_store_listing_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductStoreListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(ProductStoreListing::class, 'product_store_listing_id');
    }
}
