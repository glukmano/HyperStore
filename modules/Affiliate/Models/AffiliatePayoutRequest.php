<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Payables\Enums\PayoutRequestStatus;
use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $affiliate_id
 * @property int|null $payout_batch_id
 * @property int $amount_minor
 * @property string $currency
 * @property PayoutRequestStatus $status
 * @property array<string, mixed>|null $destination_details
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Affiliate $affiliate
 * @property-read AffiliatePayoutBatch|null $batch
 * @property-read User|null $approver
 * @property-read Collection<int, AffiliatePayoutRequestAllocation> $allocations
 */
class AffiliatePayoutRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_payout_requests';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'affiliate_id',
        'payout_batch_id',
        'amount_minor',
        'currency',
        'status',
        'destination_details',
        'approved_by_user_id',
        'paid_at',
    ];

    protected $casts = [
        'status' => PayoutRequestStatus::class,
        'amount_minor' => 'integer',
        'destination_details' => 'array',
        'paid_at' => 'immutable_datetime',
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
     * @return BelongsTo<AffiliatePayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<AffiliatePayoutRequestAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(AffiliatePayoutRequestAllocation::class, 'payout_request_id');
    }
}
