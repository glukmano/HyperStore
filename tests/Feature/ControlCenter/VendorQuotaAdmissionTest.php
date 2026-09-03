<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantResourceQuotaExceededException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\DTOs\VendorRegistrationDTO;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Services\VendorRegistrationService;
use Tests\TestCase;

class VendorQuotaAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private VendorPlan $vendorPlan;

    private User $owner;

    private VendorRegistrationService $registrationService;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = PlatformSaasPlan::create([
            'code' => 'vendor-quota-plan',
            'name' => 'Vendor Quota Plan',
            'status' => 'active',
            'limits' => ['max_vendors' => 1],
        ]);

        $this->tenant = Tenant::create(['name' => 'Vendor Quota Tenant', 'slug' => 'vq-tenant', 'status' => 'active']);
        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenant->id, $plan->id);

        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store 1', 'slug' => 's1']);

        $this->vendorPlan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Marketplace Plan',
            'code' => 'mp-plan',
        ]);

        $this->owner = User::create([
            'name' => 'V Owner',
            'email' => 'vowner@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
        ]);

        $this->registrationService = app(VendorRegistrationService::class);
    }

    public function test_vendor_registration_fails_closed_when_quota_exhausted(): void
    {
        $dto1 = new VendorRegistrationDTO(
            tenantId: $this->tenant->id,
            defaultStoreId: $this->store->id,
            vendorPlanId: $this->vendorPlan->id,
            name: 'Vendor One',
            platformSlug: 'vendor-one',
            legalName: 'Vendor One Corp',
            taxId: 'TAX1',
            email: 'v1@test.com',
            phone: '123456789',
            payoutCurrency: 'USD',
            ownerUserId: $this->owner->id
        );

        $vendor1 = $this->registrationService->registerVendor($dto1);
        $this->assertNotNull($vendor1);

        // Second registration must fail closed because limit is 1!
        $dto2 = new VendorRegistrationDTO(
            tenantId: $this->tenant->id,
            defaultStoreId: $this->store->id,
            vendorPlanId: $this->vendorPlan->id,
            name: 'Vendor Two',
            platformSlug: 'vendor-two',
            legalName: 'Vendor Two Corp',
            taxId: 'TAX2',
            email: 'v2@test.com',
            phone: '987654321',
            payoutCurrency: 'USD',
            ownerUserId: $this->owner->id
        );

        $this->expectException(TenantResourceQuotaExceededException::class);
        $this->registrationService->registerVendor($dto2);
    }
}
