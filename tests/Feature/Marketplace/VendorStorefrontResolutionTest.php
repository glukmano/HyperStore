<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\DomainVerificationResolverInterface;
use Modules\Marketplace\Contracts\VendorStorefrontResolverInterface;
use Modules\Marketplace\Enums\VendorDomainStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\VendorNotFoundException;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorStorefrontProfile;
use Modules\Marketplace\Models\VendorStoreParticipation;
use Modules\Marketplace\Services\VendorDomainVerificationService;
use Tests\TestCase;
use Tests\Unit\Marketplace\Fakes\FakeDomainVerificationResolver;

class VendorStorefrontResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Vendor $vendor;

    private Store $store;

    private VendorStorefrontResolverInterface $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Storefront Tenant', 'slug' => 'sf-tenant']);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Store',
            'slug' => 'main-store',
            'code' => 'main',
            'default_currency' => 'EUR',
            'is_active' => true,
        ]);

        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Storefront Plan',
            'code' => 'sf-plan',
        ]);
        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'default_store_id' => $this->store->id,
            'name' => 'Storefront Vendor',
            'platform_slug' => 'storefront-vendor',
            'legal_name' => 'Storefront Vendor Corp',
            'email' => 'sf@vendor.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        // Enable store participation
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'store_id' => $this->store->id,
            'is_enabled' => true,
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
        $this->assertNotNull($resolved->store);
        $this->assertSame($this->store->id, $resolved->store->id);
    }

    public function test_resolve_by_subdomain(): void
    {
        $resolved = $this->resolver->resolveBySubdomain('storefront-vendor');
        $this->assertSame($this->vendor->id, $resolved->vendor->id);
        $this->assertSame('subdomain', $resolved->resolutionType);
    }

    public function test_resolve_by_verified_and_activated_custom_domain(): void
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

    public function test_cross_tenant_store_is_rejected(): void
    {
        $foreignTenant = Tenant::create(['name' => 'Foreign Tenant', 'slug' => 'foreign-tenant']);
        $foreignStore = Store::create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Foreign Store',
            'slug' => 'foreign-store',
            'code' => 'foreign',
            'default_currency' => 'EUR',
            'is_active' => true,
        ]);

        $this->expectException(VendorNotFoundException::class);
        $this->resolver->resolveByPath('storefront-vendor', $foreignStore->id);
    }

    public function test_vendor_not_participating_in_same_tenant_store_is_rejected(): void
    {
        $secondStore = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Store',
            'slug' => 'second-store',
            'code' => 'second',
            'default_currency' => 'EUR',
            'is_active' => true,
        ]);

        // Vendor does NOT participate in $secondStore
        $this->expectException(VendorNotFoundException::class);
        $this->resolver->resolveByPath('storefront-vendor', $secondStore->id);
    }

    public function test_domain_verification_and_activation_lifecycle(): void
    {
        $fakeResolver = new FakeDomainVerificationResolver;
        $this->app->instance(DomainVerificationResolverInterface::class, $fakeResolver);
        /** @var VendorDomainVerificationService $verificationService */
        $verificationService = $this->app->make(VendorDomainVerificationService::class);

        // 1. Register domain
        $domain = $verificationService->registerDomain($this->vendor, 'cool-store.com');
        $this->assertSame(VendorDomainStatus::VerificationPending, $domain->status);

        // 2. Verify without DNS record fails
        $verified = $verificationService->verifyDomain($domain);
        $this->assertFalse($verified);
        $this->assertSame(VendorDomainStatus::VerificationPending, $domain->fresh()->status);

        // 3. Add expected DNS record and verify succeeds
        $fakeResolver->setTxtRecords('cool-store.com', [
            'hyperstore-verification='.$domain->verification_token,
        ]);
        $verified = $verificationService->verifyDomain($domain);
        $this->assertTrue($verified);
        $this->assertSame(VendorDomainStatus::Verified, $domain->fresh()->status);
        $this->assertNotNull($domain->fresh()->verified_at);

        // 4. Activate domain
        $activated = $verificationService->activateDomain($domain->fresh());
        $this->assertSame(VendorDomainStatus::Active, $activated->status);

        // 5. Resolving active domain now works
        $resolved = $this->resolver->resolveByCustomDomain('cool-store.com');
        $this->assertSame($this->vendor->id, $resolved->vendor->id);
    }
}
