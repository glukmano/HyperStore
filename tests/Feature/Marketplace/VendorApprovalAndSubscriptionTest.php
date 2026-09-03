<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\VendorApprovalPolicyInterface;
use Modules\Marketplace\Contracts\VendorPlanSubscriptionEntitlementServiceInterface;
use Modules\Marketplace\Enums\SubscriptionStatus;
use Modules\Marketplace\Exceptions\VendorPlanSubscriptionException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorPlanPrice;
use Tests\TestCase;

class VendorApprovalAndSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private VendorApprovalPolicyInterface $approvalPolicy;

    private VendorPlanSubscriptionEntitlementServiceInterface $subscriptionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Sub Tenant', 'slug' => 'sub-tenant']);
        $this->approvalPolicy = app(VendorApprovalPolicyInterface::class);
        $this->subscriptionService = app(VendorPlanSubscriptionEntitlementServiceInterface::class);
    }

    public function test_free_plan_requires_manual_approval_even_if_auto_approval_flag_set(): void
    {
        $freePlan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Free Plan',
            'code' => 'free',
            'auto_approval' => true,
        ]);
        // No paid price attached -> monthly fee is 0

        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $freePlan->id,
            'name' => 'Free Vendor',
            'platform_slug' => 'free-vendor',
            'legal_name' => 'Free Vendor Corp',
            'email' => 'free@vendor.com',
        ]);

        $canAutoApprove = $this->approvalPolicy->canAutoApprove($vendor);
        $this->assertFalse($canAutoApprove);
    }

    public function test_paid_plan_auto_approves_only_when_subscription_active_with_provenance(): void
    {
        $paidPlan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pro Plan',
            'code' => 'pro',
            'auto_approval' => true,
        ]);

        VendorPlanPrice::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $paidPlan->id,
            'currency' => 'EUR',
            'monthly_fee_minor' => 4900, // 49.00 EUR
        ]);

        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $paidPlan->id,
            'name' => 'Paid Vendor',
            'platform_slug' => 'paid-vendor',
            'legal_name' => 'Paid Vendor Corp',
            'email' => 'paid@vendor.com',
        ]);

        // Before subscription activation -> false
        $this->assertFalse($this->approvalPolicy->canAutoApprove($vendor));

        // Activate subscription with billing event provenance
        $this->subscriptionService->activateSubscription($vendor, $paidPlan, 'billing_event', 'sub_123');

        // After active subscription -> true
        $this->assertTrue($this->approvalPolicy->canAutoApprove($vendor));
    }

    public function test_test_fake_activation_source_rejected_in_production(): void
    {
        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fake Test Plan',
            'code' => 'fake-test',
        ]);

        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Fake Vendor',
            'platform_slug' => 'fake-vendor',
            'legal_name' => 'Fake Vendor Corp',
            'email' => 'fake@vendor.com',
        ]);

        // In testing environment it succeeds
        $sub = $this->subscriptionService->activateSubscription($vendor, $plan, 'test_fake');
        $this->assertSame(SubscriptionStatus::Active, $sub->status);

        // Simulate production environment
        $this->app['env'] = 'production';

        $this->expectException(VendorPlanSubscriptionException::class);
        $this->subscriptionService->assertSubscriptionActive($vendor);
    }
}
