<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Marketplace\Enums\VendorListingStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $vendor_sku
 * @property VendorListingStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Collection<int, VendorListingStoreAvailability> $storeAvailabilities
 */
class VendorListing extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_listings';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'product_id',
        'product_variant_id',
        'vendor_sku',
        'status',
    ];

    protected $casts = [
        'status' => VendorListingStatus::class,
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
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
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<VendorListingStoreAvailability, $this>
     */
    public function storeAvailabilities(): HasMany
    {
        return $this->hasMany(VendorListingStoreAvailability::class, 'vendor_listing_id');
    }
}
