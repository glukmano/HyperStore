<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Pricing\ValueObjects\MoneyValue;

class Price extends Model
{
    use BelongsToTenant;

    protected $table = 'prices';

    protected $fillable = [
        'tenant_id',
        'price_book_id',
        'product_id',
        'product_variant_id',
        'amount_minor',
        'compare_at_minor',
        'cost_minor',
        'currency',
        'status',
        'valid_from',
        'valid_until',
        'metadata',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'compare_at_minor' => 'integer',
        'cost_minor' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function tierPrices(): HasMany
    {
        return $this->hasMany(TierPrice::class);
    }

    public function getMoney(): MoneyValue
    {
        return MoneyValue::fromMinor($this->amount_minor, $this->currency);
    }

    public function getCompareAtMoney(): ?MoneyValue
    {
        return $this->compare_at_minor !== null ? MoneyValue::fromMinor($this->compare_at_minor, $this->currency) : null;
    }

    public function getCostMoney(): ?MoneyValue
    {
        return $this->cost_minor !== null ? MoneyValue::fromMinor($this->cost_minor, $this->currency) : null;
    }
}
