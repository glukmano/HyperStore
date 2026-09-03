<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $supplier_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $supplier_sku
 * @property int $canonical_wholesale_cost_minor
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Supplier $supplier
 * @property-read Product $product
 * @property-read ProductVariant|null $productVariant
 * @property-read Collection<int, SupplierOffer> $offers
 */
class SupplierProductVariant extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_product_variants';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'product_id',
        'product_variant_id',
        'supplier_sku',
        'canonical_wholesale_cost_minor',
        'currency',
    ];

    protected $casts = [
        'canonical_wholesale_cost_minor' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
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
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<SupplierOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class, 'supplier_product_variant_id');
    }
}
