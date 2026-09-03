<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\VendorCommissionQuoteServiceInterface;
use Modules\Marketplace\Exceptions\CommissionCalculationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorCommissionRule;
use Modules\Marketplace\Models\VendorPlan;
use Tests\TestCase;

class VendorCommissionCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private VendorPlan $plan;

    private Vendor $vendor;

    private VendorCommissionQuoteServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Marketplace Tenant', 'slug' => 'mp-tenant']);
        $this->plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Standard Plan',
            'code' => 'standard',
            'commission_rate_bps' => 1500, // 15%
            'fixed_fee_minor' => 50, // 0.50 EUR
            'currency' => 'EUR',
        ]);
        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $this->plan->id,
            'name' => 'Acme Vendor',
            'platform_slug' => 'acme-vendor',
            'legal_name' => 'Acme Corp',
            'email' => 'acme@test.com',
            'payout_currency' => 'EUR',
        ]);

        $this->service = app(VendorCommissionQuoteServiceInterface::class);
    }

    public function test_plan_base_commission_quoted_with_exact_integer_half_up(): void
    {
        // Basis: 10,000 minor (100.00 EUR)
        // Rate: 1500 bps (15%) -> intdiv((10000 * 1500) + 5000, 10000) = 1500
        // Fixed fee: 50 minor -> Total commission = 1,550 minor
        // Net vendor amount: 10,000 - 1,550 = 8,450 minor
        $quote = $this->service->quoteCommission(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            categoryId: null,
            basisMinor: 10000,
            currency: 'EUR'
        );

        $this->assertSame(10000, $quote->basisMinor);
        $this->assertSame(1500, $quote->rateBps);
        $this->assertSame(50, $quote->fixedFeeMinor);
        $this->assertSame(1550, $quote->commissionAmountMinor);
        $this->assertSame(8450, $quote->vendorNetAmountMinor);
        $this->assertSame('EUR', $quote->currency);
        $this->assertSame('plan_base', $quote->ruleSource);
    }

    public function test_integer_half_up_boundary_rounding_edges(): void
    {
        // 100 bps = 1.00%
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 100,
            'fixed_fee_minor' => 0,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        // Basis 49 * 100 + 5000 = 9900 -> intdiv(9900, 10000) = 0
        $quoteBelow = $this->service->quoteCommission($this->tenant->id, $this->vendor->id, null, 49, 'EUR');
        $this->assertSame(0, $quoteBelow->commissionAmountMinor);
        $this->assertSame(49, $quoteBelow->vendorNetAmountMinor);

        // Basis 50 * 100 + 5000 = 10000 -> intdiv(10000, 10000) = 1 (exact threshold rounds UP)
        $quoteExact = $this->service->quoteCommission($this->tenant->id, $this->vendor->id, null, 50, 'EUR');
        $this->assertSame(1, $quoteExact->commissionAmountMinor);
        $this->assertSame(49, $quoteExact->vendorNetAmountMinor);

        // Basis 149 * 100 + 5000 = 19900 -> intdiv(19900, 10000) = 1
        $quote149 = $this->service->quoteCommission($this->tenant->id, $this->vendor->id, null, 149, 'EUR');
        $this->assertSame(1, $quote149->commissionAmountMinor);

        // Basis 150 * 100 + 5000 = 20000 -> intdiv(20000, 10000) = 2
        $quote150 = $this->service->quoteCommission($this->tenant->id, $this->vendor->id, null, 150, 'EUR');
        $this->assertSame(2, $quote150->commissionAmountMinor);
    }

    public function test_large_integer_overflow_safety(): void
    {
        // Large financial basis: 100,000,000,000 minor units (1 billion EUR)
        $largeBasis = 100_000_000_000;

        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 250, // 2.5%
            'fixed_fee_minor' => 100,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $quote = $this->service->quoteCommission($this->tenant->id, $this->vendor->id, null, $largeBasis, 'EUR');
        // 100,000,000,000 * 250 = 25,000,000,000,000
        // (25,000,000,000,000 + 5000) / 10000 = 2,500,000,000
        // + 100 = 2,500,000,100
        $this->assertSame(2_500_000_100, $quote->commissionAmountMinor);
        $this->assertSame($largeBasis - 2_500_000_100, $quote->vendorNetAmountMinor);
    }

    public function test_explicit_zero_plan_rate_is_valid_and_recognized(): void
    {
        $zeroPlan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zero Plan',
            'code' => 'zero-plan',
            'commission_rate_bps' => 0,
            'fixed_fee_minor' => 0,
            'currency' => 'EUR',
        ]);
        $zeroVendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $zeroPlan->id,
            'name' => 'Zero Vendor',
            'platform_slug' => 'zero-vendor',
            'legal_name' => 'Zero Corp',
            'email' => 'zero@vendor.com',
        ]);

        $quote = $this->service->quoteCommission($this->tenant->id, $zeroVendor->id, null, 10000, 'EUR');
        $this->assertSame(0, $quote->commissionAmountMinor);
        $this->assertSame(10000, $quote->vendorNetAmountMinor);
        $this->assertSame('plan_base', $quote->ruleSource);
    }

    public function test_explicit_zero_tenant_rule_is_valid(): void
    {
        $tenantNoPlanVendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $this->plan->id,
            'name' => 'Tenant Rule Vendor',
            'platform_slug' => 'tenant-rule-vendor',
            'legal_name' => 'Tenant Rule Corp',
            'email' => 'tr@vendor.com',
        ]);
        // Remove plan link or set plan rate to null
        $this->plan->commission_rate_bps = 0;
        $this->plan->fixed_fee_minor = 0;
        $this->plan->save();

        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => null,
            'category_id' => null,
            'rate_basis_points' => 0,
            'fixed_fee_minor' => 0,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $quote = $this->service->quoteCommission($this->tenant->id, $tenantNoPlanVendor->id, null, 5000, 'EUR');
        $this->assertSame(0, $quote->commissionAmountMinor);
        $this->assertSame(5000, $quote->vendorNetAmountMinor);
    }

    public function test_missing_commission_configuration_fails_closed(): void
    {
        $orphanTenant = Tenant::create(['name' => 'Orphan Tenant', 'slug' => 'orphan-tenant']);
        $orphanPlan = VendorPlan::create([
            'tenant_id' => $orphanTenant->id,
            'name' => 'Orphan Plan',
            'code' => 'orphan-plan',
        ]);
        // Set plan rate bps to null directly via DB
        DB::table('vendor_plans')->where('id', $orphanPlan->id)->update(['commission_rate_bps' => null]);

        $orphanVendor = Vendor::create([
            'tenant_id' => $orphanTenant->id,
            'vendor_plan_id' => $orphanPlan->id,
            'name' => 'Orphan Vendor',
            'platform_slug' => 'orphan-vendor',
            'legal_name' => 'Orphan LLC',
            'email' => 'orphan@vendor.com',
        ]);

        $this->expectException(CommissionCalculationException::class);
        $this->expectExceptionMessage('No commission rule could be resolved');

        $this->service->quoteCommission($orphanTenant->id, $orphanVendor->id, null, 10000, 'EUR');
    }

    public function test_vendor_global_override_takes_precedence_over_plan_base(): void
    {
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 1000, // 10% override
            'fixed_fee_minor' => 20,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $quote = $this->service->quoteCommission(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            categoryId: null,
            basisMinor: 10000,
            currency: 'EUR'
        );

        $this->assertSame(1000, $quote->rateBps);
        $this->assertSame(20, $quote->fixedFeeMinor);
        $this->assertSame(1020, $quote->commissionAmountMinor);
        $this->assertSame(8980, $quote->vendorNetAmountMinor);
        $this->assertSame('vendor_global', $quote->ruleSource);
    }

    public function test_vendor_category_override_takes_precedence_over_vendor_global(): void
    {
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 1000,
            'fixed_fee_minor' => 0,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $catId = DB::table('categories')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'code' => 'electronics',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => $catId,
            'rate_basis_points' => 700, // 7% specific override
            'fixed_fee_minor' => 10,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $quote = $this->service->quoteCommission(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            categoryId: $catId,
            basisMinor: 10000,
            currency: 'EUR'
        );

        $this->assertSame(700, $quote->rateBps);
        $this->assertSame(10, $quote->fixedFeeMinor);
        $this->assertSame(710, $quote->commissionAmountMinor);
        $this->assertSame(9290, $quote->vendorNetAmountMinor);
        $this->assertSame('vendor_category', $quote->ruleSource);
    }

    public function test_negative_basis_throws_exception(): void
    {
        $this->expectException(CommissionCalculationException::class);
        $this->service->quoteCommission(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            categoryId: null,
            basisMinor: -500,
            currency: 'EUR'
        );
    }

    public function test_commission_capped_at_basis(): void
    {
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 5000,
            'fixed_fee_minor' => 2000,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $quote = $this->service->quoteCommission(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            categoryId: null,
            basisMinor: 1000,
            currency: 'EUR'
        );

        $this->assertSame(1000, $quote->commissionAmountMinor);
        $this->assertSame(0, $quote->vendorNetAmountMinor);
    }
}
