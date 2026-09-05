<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Owner Delta correction §12: a dedicated lock-anchor row whose sole purpose
 * is to serialize concurrent redemption via SELECT ... FOR UPDATE. It is
 * never itself an economic record.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_profile_id
 * @property int $loyalty_program_id
 */
class LoyaltyAccountLock extends Model
{
    use BelongsToTenant;

    protected $table = 'loyalty_account_locks';

    protected $fillable = [
        'tenant_id',
        'customer_profile_id',
        'loyalty_program_id',
    ];
}
