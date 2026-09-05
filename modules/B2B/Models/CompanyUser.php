<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\B2B\Enums\CompanyUserRole;

/**
 * Owner Delta §1: the SOLE representation of Company membership anywhere in
 * the platform — no other table (e.g. customer_profiles) duplicates it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property int $user_id
 * @property CompanyUserRole $role
 * @property bool $is_active
 */
class CompanyUser extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'user_id',
        'role',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyUserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
