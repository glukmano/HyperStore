<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Owner Delta §3: a dedicated lock-anchor row — never itself an economic
 * record. Mirrors `Modules\Promotions\Models\LoyaltyAccountLock` exactly:
 * `firstOrCreate()` then `lockForUpdate()` inside the same transaction that
 * recomputes exposure, validates, and writes an idempotent entry.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $company_credit_account_id
 */
class CompanyCreditAccountLock extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'company_credit_account_id',
    ];
}
