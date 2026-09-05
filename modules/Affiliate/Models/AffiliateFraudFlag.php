<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Affiliate\Enums\AffiliateFraudFlagType;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $affiliate_id
 * @property AffiliateFraudFlagType $flag_type
 * @property CarbonImmutable $detected_at
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Affiliate $affiliate
 */
class AffiliateFraudFlag extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_fraud_flags';

    protected $fillable = [
        'tenant_id',
        'affiliate_id',
        'flag_type',
        'detected_at',
        'details',
        'resolved_at',
        'resolution',
    ];

    protected $casts = [
        'flag_type' => AffiliateFraudFlagType::class,
        'detected_at' => 'immutable_datetime',
        'details' => 'array',
        'resolved_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
