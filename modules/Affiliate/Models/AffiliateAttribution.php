<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Affiliate\Enums\AffiliateAttributionStrategy;
use Modules\Affiliate\Enums\AffiliateTargetType;
use Modules\Order\Models\Order;

/**
 * The FROZEN attribution decision (Owner Delta correction §2), written once
 * at Order-creation time. Never mutated afterward — a manual re-attribution
 * (correction §6) marks this row superseded and creates a brand-new row
 * rather than editing history.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $order_id
 * @property int $affiliate_id
 * @property int|null $affiliate_referral_code_id
 * @property int|null $affiliate_campaign_id
 * @property AffiliateAttributionStrategy $attribution_strategy
 * @property int|null $attribution_window_days_used
 * @property int|null $attributed_click_id
 * @property string|null $visitor_token_hash
 * @property AffiliateTargetType $target_type
 * @property int|null $target_id
 * @property CarbonImmutable $attributed_at
 * @property bool $is_manual
 * @property int|null $created_by_user_id
 * @property int|null $superseded_by_attribution_id
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Affiliate $affiliate
 * @property-read Order $order
 */
class AffiliateAttribution extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_attributions';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'order_id',
        'affiliate_id',
        'affiliate_referral_code_id',
        'affiliate_campaign_id',
        'attribution_strategy',
        'attribution_window_days_used',
        'attributed_click_id',
        'visitor_token_hash',
        'target_type',
        'target_id',
        'attributed_at',
        'is_manual',
        'created_by_user_id',
        'superseded_by_attribution_id',
        'superseded_at',
    ];

    protected $casts = [
        'attribution_strategy' => AffiliateAttributionStrategy::class,
        'target_type' => AffiliateTargetType::class,
        'attribution_window_days_used' => 'integer',
        'attributed_at' => 'immutable_datetime',
        'is_manual' => 'boolean',
        'superseded_at' => 'immutable_datetime',
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

    public function isActive(): bool
    {
        return $this->superseded_by_attribution_id === null;
    }
}
