<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\DTOs\VendorRegistrationDTO;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Exceptions\SlugAlreadyTakenException;
use Modules\Marketplace\Exceptions\VendorOwnerInvariantViolationException;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorUser;
use Modules\Marketplace\Services\VendorOwnershipService;
use Modules\Marketplace\Services\VendorRegistrationService;
use Tests\TestCase;

class VendorOnboardingAndOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user1;

    private User $user2;

    private VendorPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $this->user1 = User::factory()->create();
        $this->user2 = User::factory()->create();
        $this->plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Starter Plan',
            'code' => 'starter',
            'staff_limit' => 5,
        ]);
    }

    public function test_vendor_registration_atomically_provisions_vendor_and_active_owner(): void
    {
        $service = app(VendorRegistrationService::class);

        $dto = new VendorRegistrationDTO(
            tenantId: $this->tenant->id,
            name: 'Super Vendor',
            platformSlug: 'super-vendor',
            legalName: 'Super Vendor Inc',
            email: 'vendor@super.com',
            vendorPlanId: $this->plan->id,
            ownerUserId: $this->user1->id,
        );

        $vendor = $service->registerVendor($dto);

        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'platform_slug' => 'super-vendor',
            'operational_status' => VendorOperationalStatus::Draft->value,
        ]);

        $this->assertDatabaseHas('vendor_users', [
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $vendor->id,
            'user_id' => $this->user1->id,
            'role' => VendorRole::Owner->value,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('vendor_storefront_profiles', [
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $vendor->id,
            'display_name' => 'Super Vendor',
        ]);
    }

    public function test_duplicate_global_slug_is_rejected(): void
    {
        $service = app(VendorRegistrationService::class);

        $dto1 = new VendorRegistrationDTO(
            tenantId: $this->tenant->id,
            name: 'Vendor One',
            platformSlug: 'unique-slug',
            legalName: 'Vendor One LLC',
            email: 'v1@test.com',
            vendorPlanId: $this->plan->id,
            ownerUserId: $this->user1->id,
        );
        $service->registerVendor($dto1);

        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-tenant']);
        $dto2 = new VendorRegistrationDTO(
            tenantId: $otherTenant->id,
            name: 'Vendor Two',
            platformSlug: 'unique-slug', // Global collision
            legalName: 'Vendor Two LLC',
            email: 'v2@test.com',
            vendorPlanId: $this->plan->id,
            ownerUserId: $this->user2->id,
        );

        $this->expectException(SlugAlreadyTakenException::class);
        $service->registerVendor($dto2);
    }

    public function test_active_owner_cannot_be_demoted_or_deleted_generically(): void
    {
        $service = app(VendorRegistrationService::class);

        $dto = new VendorRegistrationDTO(
            tenantId: $this->tenant->id,
            name: 'Owner Lock Vendor',
            platformSlug: 'owner-lock',
            legalName: 'Owner Lock LLC',
            email: 'owner@lock.com',
            vendorPlanId: $this->plan->id,
            ownerUserId: $this->user1->id,
        );
        $vendor = $service->registerVendor($dto);

        /** @var VendorUser $ownerMember */
        $ownerMember = VendorUser::where('vendor_id', $vendor->id)->firstOrFail();

        // Attempt generic demotion
        try {
            $ownerMember->role = VendorRole::Manager;
            $ownerMember->save();
            $this->fail('Expected demotion of active owner to fail.');
        } catch (VendorOwnerInvariantViolationException $e) {
            $this->assertStringContainsString('cannot be demoted', $e->getMessage());
        }

        // Attempt generic deactivation
        try {
            $ownerMember->is_active = false;
            $ownerMember->save();
            $this->fail('Expected deactivation of active owner to fail.');
        } catch (VendorOwnerInvariantViolationException $e) {
            $this->assertStringContainsString('cannot be demoted', $e->getMessage());
        }

        // Attempt generic deletion
        try {
            $ownerMember->delete();
            $this->fail('Expected deletion of active owner to fail.');
        } catch (VendorOwnerInvariantViolationException $e) {
            $this->assertStringContainsString('cannot be demoted', $e->getMessage());
        }
    }

    public function test_ownership_transfer_atomically_demotes_old_owner_and_promotes_new_owner(): void
    {
        $registrationService = app(VendorRegistrationService::class);
        $transferService = app(VendorOwnershipService::class);

        $dto = new VendorRegistrationDTO(
            tenantId: $this->tenant->id,
            name: 'Transfer Vendor',
            platformSlug: 'transfer-vendor',
            legalName: 'Transfer Vendor LLC',
            email: 'trans@vendor.com',
            vendorPlanId: $this->plan->id,
            ownerUserId: $this->user1->id,
        );
        $vendor = $registrationService->registerVendor($dto);

        // Execute atomic ownership transfer
        $newOwnerMember = $transferService->transferOwnership($this->tenant->id, $vendor->id, $this->user2->id);

        $this->assertSame(VendorRole::Owner, $newOwnerMember->role);
        $this->assertTrue($newOwnerMember->is_active);

        // Old owner demoted to Manager
        $oldOwnerMember = VendorUser::where('tenant_id', $this->tenant->id)
            ->where('vendor_id', $vendor->id)
            ->where('user_id', $this->user1->id)
            ->firstOrFail();

        $this->assertSame(VendorRole::Manager, $oldOwnerMember->role);

        // Exactly one active owner remains
        $activeOwnerCount = VendorUser::where('tenant_id', $this->tenant->id)
            ->where('vendor_id', $vendor->id)
            ->where('role', VendorRole::Owner->value)
            ->where('is_active', true)
            ->count();

        $this->assertSame(1, $activeOwnerCount);
    }
}
