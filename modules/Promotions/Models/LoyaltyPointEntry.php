<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Customers\Models\CustomerProfile;

/**
 * Append-only points ledger entry. Reuses App\Core\Payables\Enums\PayableAvailabilityStatus
 * for the identical pending/available/held lifecycle shape (Owner Delta
 * correction §11) — this is a points ledger, never a money ledger; it does
 * not reference modules/Ledger.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_profile_id
 * @property int $loyalty_program_id
 * @property string $entry_type
 * @property int $points
 * @property string|null $redemption_currency
 * @property int|null $redemption_value_minor
 * @property string $source_type
 * @property string $source_uuid
 * @property PayableAvailabilityStatus $availability_status
 * @property CarbonImmutable|null $available_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property-read CustomerProfile $customerProfile
 */
class LoyaltyPointEntry extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    protected $table = 'loyalty_point_entries';

    protected $fillable = [
        'tenant_id',
        'customer_profile_id',
        'loyalty_program_id',
        'entry_type',
        'points',
        'redemption_currency',
        'redemption_value_minor',
        'source_type',
        'source_uuid',
        'availability_status',
        'available_at',
        'expires_at',
    ];

    protected $casts = [
        'availability_status' => PayableAvailabilityStatus::class,
        'points' => 'integer',
        'redemption_value_minor' => 'integer',
        'available_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
