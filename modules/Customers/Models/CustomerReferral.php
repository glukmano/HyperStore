<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;

/**
 * Owner Delta correction §13: Tenant-scoped uniqueness on
 * (tenant_id, referred_customer_profile_id) enforced at the DB level — a
 * Customer can be referred at most once, ever, within a Tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $referrer_customer_profile_id
 * @property int $referred_customer_profile_id
 * @property int $customer_referral_code_id
 * @property string $status
 * @property int|null $qualifying_order_id
 * @property CarbonImmutable|null $rewarded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerProfile $referrer
 * @property-read CustomerProfile $referred
 * @property-read Order|null $qualifyingOrder
 */
class CustomerReferral extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_referrals';

    protected $fillable = [
        'tenant_id',
        'referrer_customer_profile_id',
        'referred_customer_profile_id',
        'customer_referral_code_id',
        'status',
        'qualifying_order_id',
        'rewarded_at',
    ];

    protected $casts = [
        'rewarded_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'referrer_customer_profile_id');
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'referred_customer_profile_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function qualifyingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'qualifying_order_id');
    }
}
