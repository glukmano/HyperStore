<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\PlatformSaasPlanMutationServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\SaasPlanLimitReductionException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSaasPlanHardLimitValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_reduce_hard_limit_below_tenant_usage(): void
    {
        $plan = PlatformSaasPlan::create([
            'code' => 'pro-plan',
            'name' => 'Pro Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 10],
            'feature_entitlements' => ['marketplace.enabled' => true],
        ]);

        $tenant = Tenant::create(['name' => 'Tenant 1', 'slug' => 't1', 'status' => 'active']);

        // Assign license referencing plan
        app(TenantLicenseServiceInterface::class)->assignLicense($tenant->id, $plan->id);

        // Create 8 stores under tenant (usage = 8)
        for ($i = 1; $i <= 8; $i++) {
            Store::create([
                'tenant_id' => $tenant->id,
                'name' => "Store {$i}",
                'slug' => "store-{$i}",
                'status' => 'active',
            ]);
        }

        $mutationService = app(PlatformSaasPlanMutationServiceInterface::class);

        // Reducing to 8 is allowed (usage <= proposed)
        $mutationService->updateHardLimits($plan->id, ['max_stores' => 8]);
        $this->assertSame(8, $plan->fresh()->limits['max_stores']);

        // Reducing to 7 MUST FAIL CLOSED (NO SILENT GRANDFATHERING: usage 8 > proposed 7)
        $this->expectException(SaasPlanLimitReductionException::class);
        $mutationService->updateHardLimits($plan->id, ['max_stores' => 7]);
    }
}
