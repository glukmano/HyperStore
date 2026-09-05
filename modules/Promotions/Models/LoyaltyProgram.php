<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Owner Delta correction §22: Loyalty points are non-cash, non-withdrawable,
 * discount-entitlement only. This model — and every other Loyalty model —
 * never references modules/Ledger.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $pending_hold_days
 * @property int|null $points_expire_after_days
 * @property int $referral_reward_points
 * @property bool $is_active
 * @property-read Collection<int, LoyaltyProgramCurrencyRule> $currencyRules
 */
class LoyaltyProgram extends Model
{
    use BelongsToTenant;

    protected $table = 'loyalty_programs';

    protected $fillable = [
        'tenant_id',
        'name',
        'pending_hold_days',
        'points_expire_after_days',
        'referral_reward_points',
        'is_active',
    ];

    protected $casts = [
        'pending_hold_days' => 'integer',
        'points_expire_after_days' => 'integer',
        'referral_reward_points' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<LoyaltyProgramCurrencyRule, $this>
     */
    public function currencyRules(): HasMany
    {
        return $this->hasMany(LoyaltyProgramCurrencyRule::class, 'loyalty_program_id');
    }
}
