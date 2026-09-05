<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner Delta correction §10: every monetary earn/redemption rate carries an
 * EXPLICIT currency. A currency with no rule here simply cannot earn or
 * redeem points — never an implicit conversion from another currency's rate.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $loyalty_program_id
 * @property string $currency
 * @property int $minor_units_per_point
 * @property int $point_redemption_value_minor
 * @property bool $is_active
 */
class LoyaltyProgramCurrencyRule extends Model
{
    use BelongsToTenant;

    protected $table = 'loyalty_program_currency_rules';

    protected $fillable = [
        'tenant_id',
        'loyalty_program_id',
        'currency',
        'minor_units_per_point',
        'point_redemption_value_minor',
        'is_active',
    ];

    protected $casts = [
        'minor_units_per_point' => 'integer',
        'point_redemption_value_minor' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<LoyaltyProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }
}
