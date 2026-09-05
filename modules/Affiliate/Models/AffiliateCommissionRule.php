<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Category;

/**
 * Mirrors Modules\Marketplace\Models\VendorCommissionRule exactly. currency
 * is load-bearing (Owner Delta correction §14): a rule only ever applies to
 * commission bases already denominated in this exact currency — no implicit
 * conversion, ever.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $affiliate_id
 * @property int|null $affiliate_campaign_id
 * @property int|null $category_id
 * @property int $rate_basis_points
 * @property int $fixed_fee_minor
 * @property string $currency
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Affiliate|null $affiliate
 * @property-read AffiliateCampaign|null $campaign
 * @property-read Category|null $category
 */
class AffiliateCommissionRule extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_commission_rules';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'affiliate_id',
        'affiliate_campaign_id',
        'category_id',
        'rate_basis_points',
        'fixed_fee_minor',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'rate_basis_points' => 'integer',
        'fixed_fee_minor' => 'integer',
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

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
