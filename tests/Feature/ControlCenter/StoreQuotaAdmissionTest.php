<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantResourceQuotaExceededException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreQuotaAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private StoreCreationServiceInterface $storeService;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = PlatformSaasPlan::create([
            'code' => 'store-quota-plan',
            'name' => 'Store Quota Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 2],
        ]);

        $this->tenant = Tenant::create(['name' => 'Store Tenant', 'slug' => 'st-ten', 'status' => 'active']);
        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenant->id, $plan->id);

        $this->storeService = app(StoreCreationServiceInterface::class);
    }

    public function test_store_creation_succeeds_under_quota_and_fails_closed_at_quota(): void
    {
        // Store 1: succeeds (usage 0 -> 1)
        $store1 = $this->storeService->createStore($this->tenant->id, ['name' => 'Store 1', 'slug' => 's1']);
        $this->assertNotNull($store1);

        // Store 2: succeeds (usage 1 -> 2)
        $store2 = $this->storeService->createStore($this->tenant->id, ['name' => 'Store 2', 'slug' => 's2']);
        $this->assertNotNull($store2);

        // Store 3: FAILS CLOSED (usage 2 >= limit 2)
        $this->expectException(TenantResourceQuotaExceededException::class);
        $this->storeService->createStore($this->tenant->id, ['name' => 'Store 3', 'slug' => 's3']);
    }

    public function test_store_deletion_releases_quota(): void
    {
        $store1 = $this->storeService->createStore($this->tenant->id, ['name' => 'Store A', 'slug' => 'sa']);
        $store2 = $this->storeService->createStore($this->tenant->id, ['name' => 'Store B', 'slug' => 'sb']);

        // Delete store 1 (usage drops to 1)
        $store1->delete();

        // Now creating another store succeeds
        $store3 = $this->storeService->createStore($this->tenant->id, ['name' => 'Store C', 'slug' => 'sc']);
        $this->assertNotNull($store3);
    }
}
