<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Models;

use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\SuperAdmin\Models\TenantLicense;
use App\Core\Tenancy\Enums\TenantOperationalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TenantOperationalStatus|string $status
 * @property ?int $owner_id
 * @property string $customer_account_scope
 * @property ?array<string, mixed> $settings
 */
class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
        'owner_id',
        'customer_account_scope',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantOperationalStatus::class,
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $tenant): void {
            if (app()->bound(TenantLicenseServiceInterface::class)) {
                try {
                    $defaultPlan = PlatformSaasPlan::firstOrCreate(
                        ['code' => 'standard'],
                        [
                            'name' => 'Standard Plan',
                            'status' => 'active',
                            'limits' => [
                                'max_stores' => 100,
                                'max_vendors' => 100,
                                'max_products' => 1000,
                            ],
                            'feature_entitlements' => [
                                'marketplace.enabled' => true,
                            ],
                        ]
                    );

                    if (! TenantLicense::where('tenant_id', $tenant->id)->exists()) {
                        app(TenantLicenseServiceInterface::class)->assignLicense(
                            $tenant->id,
                            $defaultPlan->id
                        );
                    }
                } catch (\Throwable) {
                    // Ignore during raw migrations before platform_saas_plans table exists
                }
            }
        });
    }

    public function isActive(): bool
    {
        if ($this->status instanceof TenantOperationalStatus) {
            return $this->status->isActive();
        }

        return $this->status === 'active';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<TenantUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'tenant_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'tenant_id');
    }

    /**
     * @return HasMany<Market, $this>
     */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class, 'tenant_id');
    }
}
