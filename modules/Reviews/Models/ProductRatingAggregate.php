<?php

declare(strict_types=1);

namespace Modules\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Product;

/**
 * Denormalized, safely-recomputable aggregate owned by Reviews — never a
 * column added onto Catalog's products table (module boundary).
 *
 * @property int $product_id
 * @property int $tenant_id
 * @property float $average_rating
 * @property int $review_count
 */
class ProductRatingAggregate extends Model
{
    protected $primaryKey = 'product_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['product_id', 'tenant_id', 'average_rating', 'review_count', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'tenant_id' => 'integer',
            'average_rating' => 'decimal:2',
            'review_count' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
