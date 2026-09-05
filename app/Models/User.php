<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Context\Contracts\RegionalPreferenceProviderInterface;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?string $phone
 * @property string $status
 * @property bool $is_super_admin
 * @property string $default_locale
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_super_admin',
        'status',
        'default_locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return HasMany<TenantUser, $this>
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'user_id');
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Store, $this>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_users')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    public function isMemberOfTenant(int $tenantId): bool
    {
        return $this->tenantMemberships()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();
    }

    public function isTenantAdmin(int $tenantId): bool
    {
        $membership = $this->tenantMemberships()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        return $membership !== null && $membership->isAdmin();
    }

    /**
     * Phase-18 Final Completion Delta §6(D): Laravel's own notification
     * pipeline (NotificationSender::preferredLocale()) checks this
     * interface and wraps rendering in App::setLocale($locale) for BOTH
     * immediate and queued sends — so a queued notification renders in
     * the RECIPIENT's own resolved locale, never whatever locale happens
     * to be ambient in the queue worker process. CustomerProfile's
     * synced preferred_locale (kept current by the storefront switcher)
     * takes priority over the plain default_locale column, since it's
     * the one actively maintained by user action; default_locale (also
     * meaningful for non-customer staff, who never get a CustomerProfile)
     * is the fallback.
     */
    public function preferredLocale(): ?string
    {
        $preferred = app(RegionalPreferenceProviderInterface::class)->getPreferredLocale($this->id);
        if ($preferred !== null) {
            return $preferred;
        }

        return $this->default_locale !== '' ? $this->default_locale : null;
    }
}
