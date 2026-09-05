<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Affiliate\Enums\AffiliateConversionStatus;
use Modules\Order\Models\Order;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $affiliate_attribution_id
 * @property int $affiliate_id
 * @property int $order_id
 * @property string $currency
 * @property AffiliateConversionStatus $status
 * @property CarbonImmutable $converted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AffiliateAttribution $attribution
 * @property-read Affiliate $affiliate
 * @property-read Order $order
 * @property-read Collection<int, AffiliateConversionItem> $items
 */
class AffiliateConversion extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_conversions';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'affiliate_attribution_id',
        'affiliate_id',
        'order_id',
        'currency',
        'status',
        'converted_at',
    ];

    protected $casts = [
        'status' => AffiliateConversionStatus::class,
        'converted_at' => 'immutable_datetime',
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
     * @return BelongsTo<AffiliateAttribution, $this>
     */
    public function attribution(): BelongsTo
    {
        return $this->belongsTo(AffiliateAttribution::class, 'affiliate_attribution_id');
    }

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return HasMany<AffiliateConversionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AffiliateConversionItem::class, 'affiliate_conversion_id');
    }
}
