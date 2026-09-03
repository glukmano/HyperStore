<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantLicenseOverrideException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLicenseOverrideSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_reduce_override_below_current_usage(): void
    {
        $plan = PlatformSaasPlan::create([
            'code' => 'basic-plan',
            'name' => 'Basic Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 5],
        ]);

        $tenant = Tenant::create(['name' => 'Tenant Override', 'slug' => 't-ov', 'status' => 'active']);

        $licenseService = app(TenantLicenseServiceInterface::class);
        $licenseService->assignLicense($tenant->id, $plan->id, overrideLimits: ['max_stores' => 10]);

        // Create 7 stores
        for ($i = 1; $i <= 7; $i++) {
            Store::create(['tenant_id' => $tenant->id, 'name' => "S {$i}", 'slug' => "s-{$i}"]);
        }

        // Attempting to reduce override to 6 (usage = 7) MUST FAIL CLOSED
        $this->expectException(TenantLicenseOverrideException::class);
        $licenseService->updateOverrides($tenant->id, overrideLimits: ['max_stores' => 6], overrideFeatures: []);
    }
}
