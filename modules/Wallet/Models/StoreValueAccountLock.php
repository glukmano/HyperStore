<?php

declare(strict_types=1);

namespace Modules\Wallet\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dedicated lock-anchor row, never itself an economic record — mirrors
 * LoyaltyAccountLock/CompanyCreditAccountLock exactly.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_value_account_id
 */
class StoreValueAccountLock extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'store_value_account_id',
    ];

    /**
     * @return BelongsTo<StoreValueAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(StoreValueAccount::class, 'store_value_account_id');
    }
}
