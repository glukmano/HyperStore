<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\CarrierProviderInterface;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\Registries\CarrierRegistry;
use Modules\Shipping\ValueObjects\CarrierRateResult;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use RuntimeException;
use Tests\TestCase;

class FailingCarrierProvider implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        throw new RuntimeException('External Carrier API Timeout (504 Gateway Timeout)');
    }
}

class WorkingCarrierProvider implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        return [
            new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: 'EXP_24',
                serviceName: 'Express 24h',
                rateAmount: MoneyValue::fromMinor(2200, $request->context->currency),
                transitDaysMin: 1,
                transitDaysMax: 1
            ),
        ];
    }
}

class CarrierTimeoutAndFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Timeout Tenant', 'slug' => 'timeout-tenant', 'status' => 'active']);
    }

    public function test_static_rate_succeeds_even_when_carrier_provider_fails(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('failing_provider', FailingCarrierProvider::class, override: true);

        $carrier = Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FAIL_CARRIER',
            'name' => 'Failing Carrier',
            'provider_code' => 'failing_provider',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_FAIL_TEST', 'name' => 'CH Fail Test', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        // Method 1: Failing Carrier calculated method
        $failMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_FAIL',
            'name' => 'Carrier Fail Method',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'FAIL_CARRIER'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $failMethod->id, 'shipping_zone_id' => $zone->id]);

        // Method 2: Flat rate static method
        $flatMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FLAT_BACKUP',
            'name' => 'Flat Rate Backup',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1200,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $flatMethod->id, 'shipping_zone_id' => $zone->id]);

        $engine = app(ShippingRateEngineInterface::class);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );

        $quotes = $engine->calculateQuotes($request);

        $this->assertCount(1, $quotes);
        $this->assertSame('FLAT_BACKUP', $quotes->first()?->methodCode);
    }

    public function test_provider_a_fails_provider_b_succeeds_and_static_succeeds(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('failing_provider_a', FailingCarrierProvider::class, override: true);
        $carrierRegistry->register('working_provider_b', WorkingCarrierProvider::class, override: true);

        // Carrier A (failing)
        Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_A',
            'name' => 'Carrier A',
            'provider_code' => 'failing_provider_a',
            'status' => 'active',
        ]);

        // Carrier B (working)
        Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_B',
            'name' => 'Carrier B',
            'provider_code' => 'working_provider_b',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_MULTI_TEST', 'name' => 'CH Multi', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        // Method A: Carrier A (Failing)
        $methodA = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_CARRIER_A',
            'name' => 'Method Carrier A',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'priority' => 10,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_A'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodA->id, 'shipping_zone_id' => $zone->id]);

        // Method B: Carrier B (Working)
        $methodB = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_CARRIER_B',
            'name' => 'Method Carrier B',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'priority' => 20,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_B', 'service_code' => 'EXP_24'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodB->id, 'shipping_zone_id' => $zone->id]);

        // Method C: Flat rate static
        $methodC = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_STATIC',
            'name' => 'Method Static',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 900,
            'priority' => 5,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodC->id, 'shipping_zone_id' => $zone->id]);

        $engine = app(ShippingRateEngineInterface::class);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );

        $quotes = $engine->calculateQuotes($request);

        // Expected: Method B (Carrier B) and Method C (Static) are returned. Method A (Carrier A) is safely omitted.
        $this->assertCount(2, $quotes);
        $methodCodes = $quotes->pluck('methodCode')->all();
        $this->assertContains('METHOD_CARRIER_B', $methodCodes);
        $this->assertContains('METHOD_STATIC', $methodCodes);
        $this->assertNotContains('METHOD_CARRIER_A', $methodCodes);
    }

    public function test_all_providers_fail_returns_structured_safe_result_without_raw_exception_leakage(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('failing_provider_all', FailingCarrierProvider::class, override: true);

        Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ALL_FAIL',
            'name' => 'All Fail Carrier',
            'provider_code' => 'failing_provider_all',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ALL_FAIL', 'name' => 'CH All Fail', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ONLY_FAILING_METHOD',
            'name' => 'Only Failing',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'ALL_FAIL'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        $engine = app(ShippingRateEngineInterface::class);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );

        // Does NOT throw unhandled exception — returns empty collection safely
        $quotes = $engine->calculateQuotes($request);
        $this->assertEmpty($quotes);
    }
}
