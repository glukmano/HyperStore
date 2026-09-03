<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\VendorStorefrontResolverInterface;
use Modules\Marketplace\Enums\VendorDomainStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\VendorNotFoundException;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorStorefrontProfile;
use Tests\TestCase;

class VendorStorefrontResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Vendor $vendor;

    private VendorStorefrontResolverInterface $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Storefront Tenant', 'slug' => 'sf-tenant']);
        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Storefront Plan',
            'code' => 'sf-plan',
        ]);
        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Storefront Vendor',
            'platform_slug' => 'storefront-vendor',
            'legal_name' => 'Storefront Vendor Corp',
            'email' => 'sf@vendor.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        VendorStorefrontProfile::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'display_name' => 'Storefront Vendor Official Store',
        ]);

        $this->resolver = app(VendorStorefrontResolverInterface::class);
    }

    public function test_resolve_by_path(): void
    {
        $resolved = $this->resolver->resolveByPath('storefront-vendor');
        $this->assertSame($this->vendor->id, $resolved->vendor->id);
        $this->assertSame('path', $resolved->resolutionType);
        $this->assertSame('/storefront-vendor', $resolved->canonicalUrl);
    }

    public function test_resolve_by_subdomain(): void
    {
        $resolved = $this->resolver->resolveBySubdomain('storefront-vendor');
        $this->assertSame($this->vendor->id, $resolved->vendor->id);
        $this->assertSame('subdomain', $resolved->resolutionType);
    }

    public function test_resolve_by_verified_custom_domain(): void
    {
        VendorDomain::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'domain' => 'vendor-custom.com',
            'verification_token' => 'tok_123',
            'status' => VendorDomainStatus::Active,
        ]);

        $resolved = $this->resolver->resolveByCustomDomain('vendor-custom.com');
        $this->assertSame($this->vendor->id, $resolved->vendor->id);
        $this->assertSame('custom_domain', $resolved->resolutionType);
        $this->assertSame('https://vendor-custom.com', $resolved->canonicalUrl);
    }

    public function test_unverified_custom_domain_cannot_be_resolved(): void
    {
        VendorDomain::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'domain' => 'unverified.com',
            'verification_token' => 'tok_456',
            'status' => VendorDomainStatus::VerificationPending,
        ]);

        $this->expectException(VendorNotFoundException::class);
        $this->resolver->resolveByCustomDomain('unverified.com');
    }

    public function test_inactive_vendor_cannot_be_resolved(): void
    {
        $this->vendor->operational_status = VendorOperationalStatus::Suspended;
        $this->vendor->save();

        $this->expectException(VendorOperationalStatusException::class);
        $this->resolver->resolveByPath('storefront-vendor');
    }
}
