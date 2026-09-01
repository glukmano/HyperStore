<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Calculators\CarrierCalculatedRateCalculator;
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
use Tests\TestCase;

class MultiServiceCarrierProvider implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        return [
            new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: 'ECONOMY',
                serviceName: 'Economy Ground',
                rateAmount: MoneyValue::fromMinor(1000, $request->context->currency),
                transitDaysMin: 3,
                transitDaysMax: 5
            ),
            new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: 'EXPRESS',
                serviceName: 'Express Air',
                rateAmount: MoneyValue::fromMinor(1999, $request->context->currency),
                transitDaysMin: 1,
                transitDaysMax: 2
            ),
        ];
    }
}

class ReverseMultiServiceCarrierProvider implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        return [
            new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: 'EXPRESS',
                serviceName: 'Express Air',
                rateAmount: MoneyValue::fromMinor(1999, $request->context->currency),
                transitDaysMin: 1,
                transitDaysMax: 2
            ),
            new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: 'ECONOMY',
                serviceName: 'Economy Ground',
                rateAmount: MoneyValue::fromMinor(1000, $request->context->currency),
                transitDaysMin: 3,
                transitDaysMax: 5
            ),
        ];
    }
}

class CarrierProviderAndCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Carrier Tenant', 'slug' => 'carrier-test', 'status' => 'active']);
    }

    public function test_carrier_rate_selects_explicit_service_regardless_of_provider_array_order(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('multi_srv_a', MultiServiceCarrierProvider::class, override: true);
        $carrierRegistry->register('multi_srv_b', ReverseMultiServiceCarrierProvider::class, override: true);

        // Carrier A (returns ECONOMY first, EXPRESS second)
        $carrierA = Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_A',
            'name' => 'Carrier A',
            'provider_code' => 'multi_srv_a',
            'status' => 'active',
        ]);

        // Carrier B (returns EXPRESS first, ECONOMY second)
        $carrierB = Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_B',
            'name' => 'Carrier B',
            'provider_code' => 'multi_srv_b',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        // Method A binds explicitly to EXPRESS on Carrier A
        $methodA = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_EXP_A',
            'name' => 'Method Express A',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_A', 'service_code' => 'EXPRESS'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodA->id, 'shipping_zone_id' => $zone->id]);

        // Method B binds explicitly to EXPRESS on Carrier B
        $methodB = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_EXP_B',
            'name' => 'Method Express B',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_B', 'service_code' => 'EXPRESS'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodB->id, 'shipping_zone_id' => $zone->id]);

        $engine = app(ShippingRateEngineInterface::class);
        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );

        $result = $engine->calculateQuotes($request);
        $this->assertCount(2, $result->quotes);

        // Both methods got EXPRESS rate (1999 minor), never ECONOMY (1000 minor)
        $this->assertSame(1999, $result->quotes[0]->amount->getMinorAmount());
        $this->assertSame('EXPRESS', $result->quotes[0]->serviceCode);
        $this->assertSame(1999, $result->quotes[1]->amount->getMinorAmount());
        $this->assertSame('EXPRESS', $result->quotes[1]->serviceCode);
    }

    public function test_missing_or_unknown_service_code_returns_null_safely(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('multi_srv_a', MultiServiceCarrierProvider::class, override: true);

        $carrier = Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_SRV_TEST',
            'name' => 'Carrier Test',
            'provider_code' => 'multi_srv_a',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE_SRV', 'name' => 'CH Zone Srv', 'status' => 'active']);

        // Method 1: Missing service_code
        $mMissing = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M_MISSING',
            'name' => 'Missing Service Code',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_SRV_TEST'],
        ]);

        // Method 2: Unknown service_code
        $mUnknown = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M_UNKNOWN',
            'name' => 'Unknown Service Code',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_SRV_TEST', 'service_code' => 'NON_EXISTENT_999'],
        ]);

        $calc = app(CarrierCalculatedRateCalculator::class);
        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: []
        );

        $this->assertNull($calc->calculate($mMissing, $zone, $request));
        $this->assertNull($calc->calculate($mUnknown, $zone, $request));
    }

    public function test_exact_carrier_markup_bcmath_arithmetic_and_rounding(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('multi_srv_a', MultiServiceCarrierProvider::class, override: true);

        $carrier = Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_MARKUP_TEST',
            'name' => 'Carrier Markup Test',
            'provider_code' => 'multi_srv_a',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE_M', 'name' => 'CH Zone M', 'status' => 'active']);

        // Base rate for EXPRESS is 1999 minor (19.99 CHF)
        // Fixed markup = 500 minor (5.00 CHF)
        // Percentage markup = 7.25%
        // Calculation: 1999 * 0.0725 = 144.9275 minor -> rounds half up to 145 minor
        // Total markup = 500 + 145 = 645 minor
        // Handling fee = 200 minor
        // Final amount = 1999 + 645 + 200 = 2844 minor
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M_MARKUP_EXACT',
            'name' => 'Markup Exact',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'handling_fee' => 200,
            'status' => 'active',
            'metadata' => [
                'carrier_code' => 'CARRIER_MARKUP_TEST',
                'service_code' => 'EXPRESS',
                'markup_amount' => 500,
                'markup_percentage' => '7.2500',
            ],
        ]);

        $calc = app(CarrierCalculatedRateCalculator::class);
        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: []
        );

        $breakdown = $calc->calculate($method, $zone, $request);
        $this->assertNotNull($breakdown);

        $this->assertSame(1999, $breakdown->baseRate->getMinorAmount());
        $this->assertSame(200, $breakdown->handlingFee->getMinorAmount());
        $this->assertSame(645, $breakdown->carrierMarkup->getMinorAmount());
        $this->assertSame(2844, $breakdown->finalAmount->getMinorAmount());
    }
}
