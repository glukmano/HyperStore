<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\VendorPayableAvailabilityPolicyInterface;
use Modules\Marketplace\Exceptions\CrossTenantMarketplaceException;
use Modules\Marketplace\Exceptions\InvalidVendorPayableAvailabilityPolicyException;
use Modules\Marketplace\Exceptions\MissingVendorPayableAvailabilityPolicyException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Services\VendorPayableAvailabilityPolicy;
use Modules\Marketplace\Services\VendorPayableSubledgerService;
use Tests\TestCase;

class VendorPayableAvailabilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private VendorPayableAvailabilityPolicyInterface $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new VendorPayableAvailabilityPolicy;
    }

    /**
     * Test A: Store hold policy = 7, Tenant hold policy = 14 -> Store wins = 7
     */
    public function test_scenario_a_store_policy_wins_over_tenant_policy(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => 14,
                ],
            ],
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Store A',
            'slug' => 'store-a',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => 7,
                ],
            ],
        ]);

        $days = $this->policy->getHoldDays($tenant->id, $store->id);
        $this->assertSame(7, $days);

        $now = CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC');
        $availableAt = $this->policy->calculateAvailableAt($tenant->id, $store->id, $now);
        $this->assertSame('2026-09-10 12:00:00', $availableAt->toDateTimeString());
    }

    /**
     * Test B: Store has no hold policy, Tenant hold policy = 14 -> Tenant = 14
     */
    public function test_scenario_b_store_unconfigured_falls_back_to_tenant_policy(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => 14,
                ],
            ],
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Store B',
            'slug' => 'store-b',
            'settings' => [],
        ]);

        $days = $this->policy->getHoldDays($tenant->id, $store->id);
        $this->assertSame(14, $days);

        $now = CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC');
        $availableAt = $this->policy->calculateAvailableAt($tenant->id, $store->id, $now);
        $this->assertSame('2026-09-17 12:00:00', $availableAt->toDateTimeString());
    }

    /**
     * Test C1: Neither Store nor Tenant configured -> policy lookup throws typed exception
     */
    public function test_scenario_c1_policy_lookup_unconfigured_fails_closed_with_typed_exception(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant C1',
            'slug' => 'tenant-c1',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Store C1',
            'slug' => 'store-c1',
            'settings' => [],
        ]);

        $this->expectException(MissingVendorPayableAvailabilityPolicyException::class);
        $this->policy->getHoldDays($tenant->id, $store->id);
    }

    /**
     * Test C2: VendorPayableSubledgerService::accrueEarning() with missing hold policy fails closed and creates zero entries
     */
    public function test_scenario_c2_accrue_earning_with_missing_hold_policy_fails_closed_and_creates_zero_entries(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant C2',
            'slug' => 'tenant-c2',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Store C2',
            'slug' => 'store-c2',
            'settings' => [],
        ]);

        $plan = VendorPlan::create([
            'tenant_id' => $tenant->id,
            'name' => 'Plan C2',
            'code' => 'plan-c2',
        ]);

        $vendor = Vendor::create([
            'tenant_id' => $tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Vendor C2',
            'platform_slug' => 'vendor-c2',
            'legal_name' => 'Vendor C2 Corp',
            'email' => 'vendor-c2@test.com',
        ]);

        $subledger = app(VendorPayableSubledgerService::class);
        $thrown = false;

        try {
            $subledger->accrueEarning(
                tenantId: $tenant->id,
                vendorId: $vendor->id,
                orderItemId: 1,
                sourceType: 'order_item',
                sourceUuid: 'oi-c2-1',
                currency: 'EUR',
                amountMinor: 10000,
                commissionMinor: 1000,
                storeId: $store->id
            );
        } catch (MissingVendorPayableAvailabilityPolicyException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected MissingVendorPayableAvailabilityPolicyException was not thrown.');
        $this->assertSame(0, VendorPayableEntry::count(), 'Zero vendor_payable_entries rows must exist when policy cannot be resolved.');
    }

    /**
     * Test D: Explicit Store from another Tenant -> cross-tenant failure -> does NOT fall back to Tenant policy
     */
    public function test_scenario_d_cross_tenant_store_fails_closed_without_tenant_fallback(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-d',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => 14,
                ],
            ],
        ]);

        $tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-d',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => 30,
                ],
            ],
        ]);

        $foreignStoreB = Store::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Foreign Store B',
            'slug' => 'foreign-store-b',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => 7,
                ],
            ],
        ]);

        $this->expectException(CrossTenantMarketplaceException::class);

        // Attempting to evaluate Tenant A with Store B must NOT use Store B (7) and must NOT fall back to Tenant A (14)
        $this->policy->getHoldDays($tenantA->id, $foreignStoreB->id);
    }

    /**
     * Test E: Explicit configured zero -> available_at equals accrual base instant -> valid explicit configuration
     */
    public function test_scenario_e_explicit_zero_hold_days_is_valid_and_available_immediately(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant E',
            'slug' => 'tenant-e',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => 0,
                ],
            ],
        ]);

        $days = $this->policy->getHoldDays($tenant->id);
        $this->assertSame(0, $days);

        $now = CarbonImmutable::parse('2026-09-03 14:00:00', 'UTC');
        $availableAt = $this->policy->calculateAvailableAt($tenant->id, null, $now);
        $this->assertSame('2026-09-03 14:00:00', $availableAt->toDateTimeString());
        $this->assertTrue($availableAt->equalTo($now));
    }

    /**
     * Test F: Negative hold days -> rejected
     */
    public function test_scenario_f_negative_hold_days_rejected(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant F',
            'slug' => 'tenant-f',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => -5,
                ],
            ],
        ]);

        $this->expectException(InvalidVendorPayableAvailabilityPolicyException::class);
        $this->policy->getHoldDays($tenant->id);
    }

    /**
     * Test G: Invalid non-integer value -> rejected
     */
    public function test_scenario_g_invalid_non_integer_values_rejected(): void
    {
        $invalidValues = ['14', 14.5, true, false, ['days' => 14], new \stdClass];

        foreach ($invalidValues as $index => $invalidValue) {
            $tenant = Tenant::create([
                'name' => "Tenant G {$index}",
                'slug' => "tenant-g-{$index}",
                'settings' => [
                    'marketplace' => [
                        'payable_hold_days' => $invalidValue,
                    ],
                ],
            ]);

            try {
                $this->policy->getHoldDays($tenant->id);
                $this->fail('Expected InvalidVendorPayableAvailabilityPolicyException was not thrown for value: '.var_export($invalidValue, true));
            } catch (InvalidVendorPayableAvailabilityPolicyException) {
                // Expected
                $this->assertTrue(true);
            }
        }
    }

    /**
     * Test H: Maximum boundary accepted
     */
    public function test_scenario_h_maximum_boundary_accepted(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant H',
            'slug' => 'tenant-h',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => VendorPayableAvailabilityPolicy::MAX_HOLD_DAYS,
                ],
            ],
        ]);

        $days = $this->policy->getHoldDays($tenant->id);
        $this->assertSame(VendorPayableAvailabilityPolicy::MAX_HOLD_DAYS, $days);
    }

    /**
     * Test I: Above maximum rejected
     */
    public function test_scenario_i_above_maximum_rejected(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant I',
            'slug' => 'tenant-i',
            'settings' => [
                'marketplace' => [
                    'payable_hold_days' => VendorPayableAvailabilityPolicy::MAX_HOLD_DAYS + 1,
                ],
            ],
        ]);

        $this->expectException(InvalidVendorPayableAvailabilityPolicyException::class);
        $this->policy->getHoldDays($tenant->id);
    }
}
