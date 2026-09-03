<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin;

use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\Stores\Services\StoreCreationService;
use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Contracts\OfficialExtensionGovernanceServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformHealthServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformReleaseServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformSaasPlanMutationServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformSettingsServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantEntitlementServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLifecycleServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantResourceEntitlementGuardInterface;
use App\Core\SuperAdmin\Services\ImpersonationService;
use App\Core\SuperAdmin\Services\OfficialExtensionGovernanceService;
use App\Core\SuperAdmin\Services\PlatformHealthService;
use App\Core\SuperAdmin\Services\PlatformReleaseService;
use App\Core\SuperAdmin\Services\PlatformSaasPlanMutationService;
use App\Core\SuperAdmin\Services\PlatformSettingsService;
use App\Core\SuperAdmin\Services\TenantEntitlementService;
use App\Core\SuperAdmin\Services\TenantLicenseService;
use App\Core\SuperAdmin\Services\TenantLifecycleService;
use App\Core\SuperAdmin\Services\TenantResourceEntitlementGuard;
use Illuminate\Support\ServiceProvider;

class SuperAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantLifecycleServiceInterface::class, TenantLifecycleService::class);
        $this->app->singleton(TenantEntitlementServiceInterface::class, TenantEntitlementService::class);
        $this->app->singleton(TenantResourceEntitlementGuardInterface::class, TenantResourceEntitlementGuard::class);
        $this->app->singleton(PlatformSaasPlanMutationServiceInterface::class, PlatformSaasPlanMutationService::class);
        $this->app->singleton(TenantLicenseServiceInterface::class, TenantLicenseService::class);
        $this->app->singleton(ImpersonationServiceInterface::class, ImpersonationService::class);
        $this->app->singleton(PlatformReleaseServiceInterface::class, PlatformReleaseService::class);
        $this->app->singleton(OfficialExtensionGovernanceServiceInterface::class, OfficialExtensionGovernanceService::class);
        $this->app->singleton(PlatformSettingsServiceInterface::class, PlatformSettingsService::class);
        $this->app->singleton(PlatformHealthServiceInterface::class, PlatformHealthService::class);
        $this->app->singleton(StoreCreationServiceInterface::class, StoreCreationService::class);
    }
}
