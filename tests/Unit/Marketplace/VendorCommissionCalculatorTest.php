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

    public function test_plan_base_commission_quoted_with_half_up_rounding(): void
    {
        // Basis: 10,000 minor (100.00 EUR)
        // Rate: 1500 bps (15%) -> 1,500 minor
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

        // Create dummy category in DB
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
        // Fixed fee exceeds basis
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 5000,
            'fixed_fee_minor' => 2000, // 20.00 EUR fee on 10.00 EUR item
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

        // Guard asserts: 0 <= total <= basis
        $this->assertSame(1000, $quote->commissionAmountMinor);
        $this->assertSame(0, $quote->vendorNetAmountMinor);
    }
}
