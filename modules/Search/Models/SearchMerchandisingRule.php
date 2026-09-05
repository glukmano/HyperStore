<?php

declare(strict_types=1);

namespace Modules\Search\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;

/**
 * @property int $id
 * @property int $tenant_id
 * @property ?int $store_id
 * @property ?string $query_term
 * @property ?int $category_id
 * @property int $product_id
 * @property ?int $pin_position
 * @property bool $is_active
 */
class SearchMerchandisingRule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'store_id', 'query_term', 'category_id', 'product_id', 'pin_position', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'store_id' => 'integer',
            'category_id' => 'integer',
            'product_id' => 'integer',
            'pin_position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
