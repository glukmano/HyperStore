<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\SuperAdmin\Contracts\TenantEntitlementServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantLicenseInactiveException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLicenseExpirationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_or_expired_license_fails_closed(): void
    {
        $plan = PlatformSaasPlan::create([
            'code' => 'ent-plan',
            'name' => 'Enterprise Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 10],
        ]);

        $tenant = Tenant::create(['name' => 'Tenant Expired', 'slug' => 't-exp', 'status' => 'active']);

        $licenseService = app(TenantLicenseServiceInterface::class);
        $license = $licenseService->assignLicense($tenant->id, $plan->id);

        // Active license passes
        $entitlementService = app(TenantEntitlementServiceInterface::class);
        $this->assertSame(10, $entitlementService->getEffectiveLimit($tenant->id, 'max_stores'));

        // Suspend license
        $licenseService->suspendLicense($tenant->id);

        $this->expectException(TenantLicenseInactiveException::class);
        $entitlementService->getEffectiveLimit($tenant->id, 'max_stores');
    }
}
