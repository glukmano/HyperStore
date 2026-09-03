<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorVerificationStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $default_store_id
 * @property int $vendor_plan_id
 * @property string $name
 * @property string $platform_slug
 * @property string $legal_name
 * @property string|null $tax_id
 * @property string $email
 * @property string|null $phone
 * @property VendorOperationalStatus $operational_status
 * @property VendorVerificationStatus $verification_status
 * @property string $payout_currency
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $terminated_at
 * @property CarbonImmutable|null $verification_submitted_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $verification_rejected_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read VendorPlan $plan
 * @property-read Store|null $defaultStore
 * @property-read Collection<int, VendorStoreParticipation> $storeParticipations
 * @property-read Collection<int, VendorUser> $users
 * @property-read VendorStorefrontProfile|null $storefrontProfile
 * @property-read Collection<int, VendorDomain> $domains
 * @property-read Collection<int, VendorListing> $listings
 * @property-read Collection<int, VendorPayableEntry> $payableEntries
 * @property-read Collection<int, PayoutRequest> $payoutRequests
 * @property-read Collection<int, VendorPlanSubscription> $subscriptions
 */
class Vendor extends Model
{
    use BelongsToTenant;

    protected $table = 'vendors';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'default_store_id',
        'vendor_plan_id',
        'name',
        'platform_slug',
        'legal_name',
        'tax_id',
        'email',
        'phone',
        'operational_status',
        'verification_status',
        'payout_currency',
        'submitted_at',
        'approved_at',
        'suspended_at',
        'terminated_at',
        'verification_submitted_at',
        'verified_at',
        'verification_rejected_at',
    ];

    protected $casts = [
        'operational_status' => VendorOperationalStatus::class,
        'verification_status' => VendorVerificationStatus::class,
        'submitted_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
        'suspended_at' => 'immutable_datetime',
        'terminated_at' => 'immutable_datetime',
        'verification_submitted_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'verification_rejected_at' => 'immutable_datetime',
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
     * @return BelongsTo<VendorPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VendorPlan::class, 'vendor_plan_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function defaultStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_store_id');
    }

    /**
     * @return HasMany<VendorStoreParticipation, $this>
     */
    public function storeParticipations(): HasMany
    {
        return $this->hasMany(VendorStoreParticipation::class, 'vendor_id');
    }

    /**
     * @return HasMany<VendorUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(VendorUser::class, 'vendor_id');
    }

    /**
     * @return HasOne<VendorStorefrontProfile, $this>
     */
    public function storefrontProfile(): HasOne
    {
        return $this->hasOne(VendorStorefrontProfile::class, 'vendor_id');
    }

    /**
     * @return HasMany<VendorDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(VendorDomain::class, 'vendor_id');
    }

    /**
     * @return HasMany<VendorListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(VendorListing::class, 'vendor_id');
    }

    /**
     * @return HasMany<VendorPayableEntry, $this>
     */
    public function payableEntries(): HasMany
    {
        return $this->hasMany(VendorPayableEntry::class, 'vendor_id');
    }

    /**
     * @return HasMany<PayoutRequest, $this>
     */
    public function payoutRequests(): HasMany
    {
        return $this->hasMany(PayoutRequest::class, 'vendor_id');
    }

    /**
     * @return HasMany<VendorPlanSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(VendorPlanSubscription::class, 'vendor_id');
    }
}
