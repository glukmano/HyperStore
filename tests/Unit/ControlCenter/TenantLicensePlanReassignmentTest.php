<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantLicensePlanReassignmentException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLicensePlanReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_reassign_to_plan_where_usage_exceeds_limits(): void
    {
        $starterPlan = PlatformSaasPlan::create([
            'code' => 'starter',
            'name' => 'Starter Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 3],
        ]);

        $growthPlan = PlatformSaasPlan::create([
            'code' => 'growth',
            'name' => 'Growth Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 10],
        ]);

        $tenant = Tenant::create(['name' => 'Tenant Reassign', 'slug' => 't-reas', 'status' => 'active']);

        $licenseService = app(TenantLicenseServiceInterface::class);
        $licenseService->assignLicense($tenant->id, $growthPlan->id);

        // Tenant creates 5 stores under Growth Plan
        for ($i = 1; $i <= 5; $i++) {
            Store::create(['tenant_id' => $tenant->id, 'name' => "Store {$i}", 'slug' => "st-{$i}"]);
        }

        // Attempting to downgrade/reassign to Starter Plan (max_stores = 3 < current usage 5) MUST FAIL CLOSED
        $this->expectException(TenantLicensePlanReassignmentException::class);
        $licenseService->reassignPlan($tenant->id, $starterPlan->id);
    }
}
