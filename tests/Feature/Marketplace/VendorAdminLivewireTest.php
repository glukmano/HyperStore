<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MarketplacePermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Livewire\VendorDetail;
use Modules\Marketplace\Livewire\VendorList;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Tests\TestCase;

class VendorAdminLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private VendorPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(MarketplacePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Vendor Admin Tenant', 'slug' => 'vendor-admin-tenant', 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));

        $this->plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Standard Plan',
            'code' => 'standard',
        ]);
    }

    private function makeVendor(VendorOperationalStatus $status = VendorOperationalStatus::Draft): Vendor
    {
        return Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $this->plan->id,
            'name' => 'Acme Vendor',
            'platform_slug' => 'acme-vendor-'.uniqid(),
            'legal_name' => 'Acme Vendor Corp',
            'email' => 'acme@vendor.test',
            'operational_status' => $status,
        ]);
    }

    public function test_vendor_list_renders_vendors_for_current_tenant(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@vendor-admin-tenant.test',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($admin);

        $vendor = $this->makeVendor();

        Livewire::test(VendorList::class)
            ->assertSee($vendor->name)
            ->assertSee($vendor->platform_slug);
    }

    public function test_unauthorized_user_cannot_suspend_vendor(): void
    {
        $unauthorized = User::create([
            'name' => 'Unauthorized User',
            'email' => 'unauth@vendor-admin-tenant.test',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($unauthorized);

        $vendor = $this->makeVendor(VendorOperationalStatus::Active);

        Livewire::test(VendorDetail::class, ['vendorId' => $vendor->id])
            ->call('suspend')
            ->assertForbidden();
    }

    public function test_authorized_admin_can_approve_pending_vendor(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin2@vendor-admin-tenant.test',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($admin);

        $vendor = $this->makeVendor(VendorOperationalStatus::PendingApproval);

        Livewire::test(VendorDetail::class, ['vendorId' => $vendor->id])
            ->call('approve')
            ->assertHasNoErrors();

        $refreshed = $vendor->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(VendorOperationalStatus::Active, $refreshed->operational_status);
    }

    public function test_invalid_transition_is_caught_and_flashed_as_error_instead_of_500(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin3@vendor-admin-tenant.test',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($admin);

        // Draft vendor cannot be approved (approveVendor requires PendingApproval).
        $vendor = $this->makeVendor(VendorOperationalStatus::Draft);

        // The invalid transition is caught internally (VendorOperationalStatusException),
        // so the Livewire call must complete normally instead of surfacing a 500.
        Livewire::test(VendorDetail::class, ['vendorId' => $vendor->id])
            ->call('approve')
            ->assertHasNoErrors()
            ->assertOk();

        // Status is unchanged since the transition was rejected by the domain service.
        $refreshed = $vendor->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(VendorOperationalStatus::Draft, $refreshed->operational_status);
    }

    public function test_vendor_detail_404s_for_vendor_outside_current_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-tenant', 'status' => 'active']);
        $otherPlan = VendorPlan::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Plan',
            'code' => 'other',
        ]);
        $foreignVendor = Vendor::create([
            'tenant_id' => $otherTenant->id,
            'vendor_plan_id' => $otherPlan->id,
            'name' => 'Foreign Vendor',
            'platform_slug' => 'foreign-vendor',
            'legal_name' => 'Foreign Vendor Corp',
            'email' => 'foreign@vendor.test',
        ]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin4@vendor-admin-tenant.test',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($admin);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(VendorDetail::class, ['vendorId' => $foreignVendor->id]);
    }
}
