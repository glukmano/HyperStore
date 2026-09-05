<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only click evidence. visitor_token_hash is the hash of a first-party
 * random attribution token (Owner Delta correction §7) — never a browser
 * fingerprint. ip_hash/user_agent are optional fraud signals only.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $affiliate_referral_code_id
 * @property string $visitor_token_hash
 * @property string|null $landing_url
 * @property string|null $referer
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property CarbonImmutable $clicked_at
 * @property-read AffiliateReferralCode $referralCode
 */
class AffiliateClick extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'affiliate_clicks';

    protected $fillable = [
        'tenant_id',
        'affiliate_referral_code_id',
        'visitor_token_hash',
        'landing_url',
        'referer',
        'ip_hash',
        'user_agent',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<AffiliateReferralCode, $this>
     */
    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(AffiliateReferralCode::class, 'affiliate_referral_code_id');
    }
}
