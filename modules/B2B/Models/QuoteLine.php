<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

/**
 * Owner Delta §3: the SOLE authoritative source of a negotiated unit price.
 * `CheckoutPricingOrchestrator` resolves the price for a Cart line through
 * this row's own id (via `cart_lines.quote_line_id`, set once, server-side,
 * when the Quote creates the Cart) — never through a client-suppliable
 * `cart_line_id => price` map.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $quote_id
 * @property int $product_id
 * @property ?int $variant_id
 * @property string $quantity
 * @property ?int $negotiated_unit_price_minor
 * @property ?string $note
 */
class QuoteLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'product_id',
        'variant_id',
        'quantity',
        'negotiated_unit_price_minor',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'negotiated_unit_price_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
