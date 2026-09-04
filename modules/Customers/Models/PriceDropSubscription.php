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

    public function shouldTrigger(int $newAmountMinor): bool
    {
        if (! $this->is_active || $this->notified_at !== null) {
            return false;
        }

        if ($this->target_price_minor !== null) {
            return $newAmountMinor <= $this->target_price_minor;
        }

        return $newAmountMinor < $this->baseline_price_minor;
    }
}
