<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\CarrierProviderInterface;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingRestriction;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\Registries\CarrierRegistry;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use RuntimeException;
use Tests\TestCase;

class FailingProviderForOutcomeTest implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        throw new RuntimeException('Carrier API is currently unavailable');
    }
}

class FakeCurrencyConverter implements CurrencyConversionInterface
{
    public function convert(MoneyValue $amount, string $targetCurrency, ?int $tenantId = null): MoneyValue
    {
        if ($amount->getCurrencyCode() === 'EUR' && $targetCurrency === 'CHF') {
            $convertedMinor = (int) round($amount->getMinorAmount() * 1.10);

            return MoneyValue::fromMinor($convertedMinor, 'CHF');
        }

        return MoneyValue::fromMinor($amount->getMinorAmount(), $targetCurrency);
    }
}

class ShippingRateCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ShippingRateEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Rate Tenant', 'slug' => 'rate-test', 'status' => 'active']);
        $this->engine = app(ShippingRateEngineInterface::class);
    }

    public function test_outcome_unfulfillable_items(): void
    {
        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]],
            hasUnfulfillableItems: true
        );

        $result = $this->engine->calculateQuotes($request);
        $this->assertSame(ShippingRateOutcome::UNFULFILLABLE_ITEMS, $result->outcome);
        $this->assertTrue($result->quotes->isEmpty());
    }

    public function test_outcome_no_method_available_when_no_zone_matched(): void
    {
        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'ZZ'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );

        $result = $this->engine->calculateQuotes($request);
        $this->assertSame(ShippingRateOutcome::NO_METHOD_AVAILABLE, $result->outcome);
        $this->assertTrue($result->quotes->isEmpty());
    }

    public function test_outcome_destination_restricted(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_RESTRICT', 'name' => 'CH Restrict', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $m = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M_RESTRICTED',
            'name' => 'Restricted Method',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m->id, 'shipping_zone_id' => $zone->id]);

        // Restrict this method for CH zone
        ShippingRestriction::create([
            'tenant_id' => $this->tenant->id,
            'shipping_method_id' => $m->id,
            'restriction_type' => 'exclude',
            'shipping_zone_id' => $zone->id,
            'reason' => 'Banned in this zone',
        ]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );

        $result = $this->engine->calculateQuotes($request);
        $this->assertSame(ShippingRateOutcome::DESTINATION_RESTRICTED, $result->outcome);
        $this->assertTrue($result->quotes->isEmpty());
    }

    public function test_outcome_provider_failure(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('fail_provider_outcome', FailingProviderForOutcomeTest::class, override: true);

        Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_OUTCOME_FAIL',
            'name' => 'Carrier Outcome Fail',
            'provider_code' => 'fail_provider_outcome',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_FAIL_Z', 'name' => 'CH Fail Z', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $m = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M_CARRIER_FAIL',
            'name' => 'M Carrier Fail',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_OUTCOME_FAIL', 'service_code' => 'STD'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m->id, 'shipping_zone_id' => $zone->id]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );

        $result = $this->engine->calculateQuotes($request);
        $this->assertSame(ShippingRateOutcome::PROVIDER_FAILURE, $result->outcome);
        $this->assertTrue($result->quotes->isEmpty());
    }

    public function test_outcome_success(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_SUCCESS', 'name' => 'CH Success', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $m = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M_SUCCESS',
            'name' => 'Success Method',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1200,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m->id, 'shipping_zone_id' => $zone->id]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );

        $result = $this->engine->calculateQuotes($request);
        $this->assertSame(ShippingRateOutcome::SUCCESS, $result->outcome);
        $this->assertCount(1, $result->quotes);
    }
}
