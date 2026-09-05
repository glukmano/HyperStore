<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Affiliate\Exceptions\AffiliateException;

/**
 * Mirrors Modules\Marketplace\Models\VendorPayableEntry exactly — a
 * self-contained, append-only, immutable-economic-fields domain subledger
 * (Owner Delta correction §1 keeps domain subledgers separate per
 * beneficiary type even though payout orchestration is shared).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $affiliate_id
 * @property int|null $affiliate_conversion_item_id
 * @property PayableEntryType $entry_type
 * @property string $source_type
 * @property string $source_uuid
 * @property string $currency
 * @property int $amount_minor
 * @property int $commission_amount_minor
 * @property int $net_amount_minor
 * @property PayableAvailabilityStatus $availability_status
 * @property CarbonImmutable|null $available_at
 * @property string|null $held_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Affiliate $affiliate
 * @property-read AffiliateConversionItem|null $conversionItem
 * @property-read Collection<int, AffiliatePayoutRequestAllocation> $allocations
 */
class AffiliatePayableEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_payable_entries';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'affiliate_id',
        'affiliate_conversion_item_id',
        'entry_type',
        'source_type',
        'source_uuid',
        'currency',
        'amount_minor',
        'commission_amount_minor',
        'net_amount_minor',
        'availability_status',
        'available_at',
        'held_reason',
    ];

    protected $casts = [
        'entry_type' => PayableEntryType::class,
        'availability_status' => PayableAvailabilityStatus::class,
        'amount_minor' => 'integer',
        'commission_amount_minor' => 'integer',
        'net_amount_minor' => 'integer',
        'available_at' => 'immutable_datetime',
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
            $immutableFields = [
                'tenant_id',
                'affiliate_id',
                'affiliate_conversion_item_id',
                'entry_type',
                'source_type',
                'source_uuid',
                'currency',
                'amount_minor',
                'commission_amount_minor',
                'net_amount_minor',
            ];

            foreach ($immutableFields as $field) {
                if ($model->isDirty($field)) {
                    throw new AffiliateException("Economic field '{$field}' on AffiliatePayableEntry is strictly immutable.");
                }
            }
        });

        static::deleting(function (self $model): void {
            throw new AffiliateException('AffiliatePayableEntry records are strictly append-only and cannot be deleted.');
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
     * @return BelongsTo<AffiliateConversionItem, $this>
     */
    public function conversionItem(): BelongsTo
    {
        return $this->belongsTo(AffiliateConversionItem::class, 'affiliate_conversion_item_id');
    }

    /**
     * @return HasMany<AffiliatePayoutRequestAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(AffiliatePayoutRequestAllocation::class, 'affiliate_payable_entry_id');
    }
}
