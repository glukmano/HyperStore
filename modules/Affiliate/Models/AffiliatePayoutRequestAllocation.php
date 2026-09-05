<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Payables\Enums\PayoutAllocationStatus;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Affiliate\Exceptions\AffiliateException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $payout_request_id
 * @property int $affiliate_payable_entry_id
 * @property int $allocated_amount_minor
 * @property PayoutAllocationStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AffiliatePayoutRequest $request
 * @property-read AffiliatePayableEntry $payableEntry
 */
class AffiliatePayoutRequestAllocation extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_payout_request_allocations';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'payout_request_id',
        'affiliate_payable_entry_id',
        'allocated_amount_minor',
        'status',
    ];

    protected $casts = [
        'status' => PayoutAllocationStatus::class,
        'allocated_amount_minor' => 'integer',
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

        static::updating(function (self $model): void {
            if ($model->isDirty('allocated_amount_minor')) {
                throw new AffiliateException('Allocated amount on AffiliatePayoutRequestAllocation is strictly immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<AffiliatePayoutRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayoutRequest::class, 'payout_request_id');
    }

    /**
     * @return BelongsTo<AffiliatePayableEntry, $this>
     */
    public function payableEntry(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayableEntry::class, 'affiliate_payable_entry_id');
    }
}
