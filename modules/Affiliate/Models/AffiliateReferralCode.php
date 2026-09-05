<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Affiliate\Enums\AffiliateTargetType;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $affiliate_id
 * @property int|null $affiliate_campaign_id
 * @property string $code
 * @property AffiliateTargetType $target_type
 * @property int|null $target_id
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Affiliate $affiliate
 * @property-read AffiliateCampaign|null $campaign
 */
class AffiliateReferralCode extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_referral_codes';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'affiliate_id',
        'affiliate_campaign_id',
        'code',
        'target_type',
        'target_id',
        'is_active',
    ];

    protected $casts = [
        'target_type' => AffiliateTargetType::class,
        'is_active' => 'boolean',
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
     * @return BelongsTo<AffiliateCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AffiliateCampaign::class, 'affiliate_campaign_id');
    }
}
