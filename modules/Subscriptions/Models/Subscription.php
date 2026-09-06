<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Customers\Models\CustomerProfile;
use Modules\Payment\Models\CustomerPaymentMethod;
use Modules\Subscriptions\Enums\SubscriptionStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $customer_profile_id
 * @property int $plan_id
 * @property ?int $pending_plan_id
 * @property int $store_id
 * @property int $market_id
 * @property int $channel_id
 * @property SubscriptionStatus $status
 * @property CarbonInterface $current_period_start
 * @property CarbonInterface $current_period_end
 * @property CarbonInterface $next_billing_at
 * @property bool $cancel_at_period_end
 * @property ?int $payment_method_id
 */
class Subscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_profile_id',
        'plan_id',
        'pending_plan_id',
        'store_id',
        'market_id',
        'channel_id',
        'status',
        'current_period_start',
        'current_period_end',
        'next_billing_at',
        'cancel_at_period_end',
        'payment_method_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'next_billing_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription): void {
            if (empty($subscription->uuid)) {
                $subscription->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'pending_plan_id');
    }

    /**
     * @return BelongsTo<CustomerPaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentMethod::class, 'payment_method_id');
    }

    /**
     * @return HasMany<SubscriptionRenewalAttempt, $this>
     */
    public function renewalAttempts(): HasMany
    {
        return $this->hasMany(SubscriptionRenewalAttempt::class);
    }
}
