<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A storefront customer's shopping profile — additive, 1:1 with User per
 * tenant (a User may hold one CustomerProfile per tenant it shops under,
 * mirroring TenantUser/VendorUser's own per-tenant-membership shape).
 * Never created for Control Center staff/vendor-staff/super-admin logins;
 * only lazily via CustomerProfileService::firstOrCreateFor().
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property bool $marketing_opt_in
 * @property ?string $birthday
 * @property ?array<string, mixed> $notification_preferences
 * @property ?string $preferred_locale
 * @property ?string $preferred_currency
 * @property ?string $preferred_timezone
 */
class CustomerProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'marketing_opt_in',
        'birthday',
        'notification_preferences',
        'preferred_locale',
        'preferred_currency',
        'preferred_timezone',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'marketing_opt_in' => 'boolean',
            'birthday' => 'date',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
