<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Marketplace\Enums\SubscriptionStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property int $vendor_plan_id
 * @property SubscriptionStatus $status
 * @property string $activation_source
 * @property string|null $external_subscription_reference
 * @property CarbonImmutable|null $current_period_starts_at
 * @property CarbonImmutable|null $current_period_ends_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 * @property-read VendorPlan $plan
 */
class VendorPlanSubscription extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_plan_subscriptions';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'vendor_plan_id',
        'status',
        'activation_source',
        'external_subscription_reference',
        'current_period_starts_at',
        'current_period_ends_at',
        'activated_at',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'current_period_starts_at' => 'immutable_datetime',
        'current_period_ends_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<VendorPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VendorPlan::class, 'vendor_plan_id');
    }
}
