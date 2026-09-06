<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Subscriptions\Enums\SubscriptionRenewalStatus;

/**
 * Owner Delta §11: itself the PostgreSQL-authoritative claim mechanism —
 * unique(subscription_id, billing_period_start) enforced from the FIRST
 * attempt of ANY outcome, before a Cart is built or a provider is called.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property CarbonInterface $billing_period_start
 * @property int $attempt_number
 * @property SubscriptionRenewalStatus $status
 * @property ?int $order_id
 * @property string $provider_idempotency_key
 * @property ?string $failure_reason
 * @property CarbonInterface $claimed_at
 * @property ?CarbonInterface $resolved_at
 */
class SubscriptionRenewalAttempt extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'billing_period_start',
        'attempt_number',
        'status',
        'order_id',
        'provider_idempotency_key',
        'failure_reason',
        'claimed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'immutable_datetime',
            'attempt_number' => 'integer',
            'status' => SubscriptionRenewalStatus::class,
            'claimed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
