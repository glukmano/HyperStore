<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $product_id
 * @property ?int $variant_id
 * @property ?int $target_price_minor
 * @property string $currency
 * @property int $baseline_price_minor
 * @property ?int $store_id
 * @property ?int $channel_id
 * @property ?int $market_id
 * @property bool $is_active
 * @property ?Carbon $notified_at
 */
class PriceDropSubscription extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'product_id',
        'variant_id',
        'target_price_minor',
        'currency',
        'baseline_price_minor',
        'store_id',
        'channel_id',
        'market_id',
        'is_active',
        'notified_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'target_price_minor' => 'integer',
            'baseline_price_minor' => 'integer',
            'store_id' => 'integer',
            'channel_id' => 'integer',
            'market_id' => 'integer',
            'is_active' => 'boolean',
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Evaluated only against a price re-resolved live through Pricing's own
     * PriceResolverInterface (never a client-supplied or event-cached
     * amount) — see CheckPriceDropSubscriptions.
     */
    public function shouldTrigger(int $currentAmountMinor): bool
    {
        if (! $this->is_active || $this->notified_at !== null) {
            return false;
        }

        if ($this->target_price_minor !== null) {
            return $currentAmountMinor <= $this->target_price_minor;
        }

        return $currentAmountMinor < $this->baseline_price_minor;
    }
}
