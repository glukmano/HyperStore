<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneAssignment;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\Registries\ShippingMethodTypeRegistry;
use Modules\Shipping\Services\ShippingRateEngine;
use Modules\Shipping\Services\ShippingRestrictionEvaluator;
use Modules\Shipping\ValueObjects\FreeShippingBenefitDTO;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class FakeCurrencyConverter implements CurrencyConversionInterface
{
    public function convert(MoneyValue $amount, string $targetCurrency, ?int $tenantId = null): MoneyValue
    {
        // 1 EUR = 1.10 CHF -> multiply by 1.10
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

    public function test_quotes_are_sorted_by_priority_desc_then_amount_asc(): void
    {
        $zone = ShippingZone::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CH_GLOBAL',
            'name' => 'CH Global',
            'priority' => 10,
            'status' => 'active',
        ]);

        ShippingZoneRule::create([
            'shipping_zone_id' => $zone->id,
            'rule_type' => 'country',
            'country_code' => 'CH',
            'is_exclusion' => false,
        ]);

        // Method 1: Low Priority (5), Low Amount (1000)
        $m1 = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STANDARD_LOW_PRIO',
            'name' => 'Standard',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'handling_fee' => 0,
            'priority' => 5,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m1->id, 'shipping_zone_id' => $zone->id]);

        // Method 2: High Priority (10), Higher Amount (2500)
        $m2 = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'EXPRESS_HIGH_PRIO',
            'name' => 'Express',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 2500,
            'handling_fee' => 0,
            'priority' => 10,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m2->id, 'shipping_zone_id' => $zone->id]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::of('1.0', 'kg'), 'is_shippable' => true],
            ]
        );

        $result = $this->engine->calculateQuotes($request);

        $this->assertSame(ShippingRateOutcome::SUCCESS, $result->outcome);
        $this->assertCount(2, $result->quotes);
        // Priority 10 comes FIRST even though amount is higher
        $this->assertSame('EXPRESS_HIGH_PRIO', $result->quotes[0]->methodCode);
        $this->assertSame('STANDARD_LOW_PRIO', $result->quotes[1]->methodCode);
    }

    public function test_typed_free_shipping_promotion_benefit_contract(): void
    {
        $zone = ShippingZone::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CH_ZONE',
            'name' => 'CH Zone',
            'priority' => 10,
            'status' => 'active',
        ]);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH', 'is_exclusion' => false]);

        $m = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FREE_ELIGIBLE',
            'name' => 'Free Eligible Method',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1500,
            'handling_fee' => 200,
            'priority' => 10,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m->id, 'shipping_zone_id' => $zone->id]);

        $benefit = new FreeShippingBenefitDTO(applicableMethodCode: 'FREE_ELIGIBLE');

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::of('1.0', 'kg'), 'is_shippable' => true],
            ],
            promotionBenefits: [$benefit]
        );

        $result = $this->engine->calculateQuotes($request);

        $this->assertSame(ShippingRateOutcome::SUCCESS, $result->outcome);
        $this->assertCount(1, $result->quotes);
        $this->assertSame(0, $result->quotes[0]->amount->getMinorAmount());
        $this->assertSame(1700, $result->quotes[0]->breakdown->promotionDiscount->getMinorAmount());
        $this->assertSame(1500, $result->quotes[0]->breakdown->baseRate->getMinorAmount());
        $this->assertSame(200, $result->quotes[0]->breakdown->handlingFee->getMinorAmount());
    }

    public function test_currency_conversion_preserves_every_breakdown_component(): void
    {
        $zone = ShippingZone::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'EU_ZONE',
            'name' => 'EU Zone',
            'status' => 'active',
        ]);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'DE']);

        // Method configured in EUR: base 1000 EUR, handling 200 EUR
        $m = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'EUR_METHOD',
            'name' => 'EUR Method',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'EUR',
            'base_amount' => 1000,
            'handling_fee' => 200,
            'priority' => 10,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m->id, 'shipping_zone_id' => $zone->id]);

        $customEngine = new ShippingRateEngine(
            zoneMatcher: app(ShippingZoneMatcherInterface::class),
            methodTypeRegistry: app(ShippingMethodTypeRegistry::class),
            restrictionEvaluator: app(ShippingRestrictionEvaluator::class),
            currencyConverter: new FakeCurrencyConverter
        );

        // Request in CHF (1 EUR = 1.10 CHF)
        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'DE'),
            lines: [
                ['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::of('1.0', 'kg'), 'is_shippable' => true],
            ]
        );

        $result = $customEngine->calculateQuotes($request);

        $this->assertSame(ShippingRateOutcome::SUCCESS, $result->outcome);
        $this->assertCount(1, $result->quotes);
        $quote = $result->quotes[0];

        // Base rate: 1000 EUR * 1.10 = 1100 CHF
        $this->assertSame(1100, $quote->breakdown->baseRate->getMinorAmount());
        $this->assertSame('CHF', $quote->breakdown->baseRate->getCurrencyCode());

        // Handling fee: 200 EUR * 1.10 = 220 CHF
        $this->assertSame(220, $quote->breakdown->handlingFee->getMinorAmount());
        $this->assertSame('CHF', $quote->breakdown->handlingFee->getCurrencyCode());

        // Final Amount: 1100 + 220 = 1320 CHF
        $this->assertSame(1320, $quote->amount->getMinorAmount());
        $this->assertSame('CHF', $quote->amount->getCurrencyCode());
        $this->assertSame(1320, $quote->breakdown->finalAmount->getMinorAmount());
    }

    public function test_shipping_zone_store_market_channel_scoping(): void
    {
        $store1 = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'STORE_1', 'name' => 'Store 1', 'slug' => 'store-1', 'status' => 'active']);
        $zone = ShippingZone::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STORE_1_ONLY',
            'name' => 'Store 1 Only Zone',
            'status' => 'active',
        ]);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        ShippingZoneAssignment::create([
            'shipping_zone_id' => $zone->id,
            'store_id' => $store1->id,
            'market_id' => null,
            'channel_id' => null,
        ]);

        $m = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_STORE_1',
            'name' => 'Store 1 Method',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 500,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $m->id, 'shipping_zone_id' => $zone->id]);

        // Request with Store 2 -> No quotes
        $reqStore2 = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF', storeId: 2),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );
        $resStore2 = $this->engine->calculateQuotes($reqStore2);
        $this->assertSame(ShippingRateOutcome::NO_METHOD_AVAILABLE, $resStore2->outcome);
        $this->assertTrue($resStore2->quotes->isEmpty());

        // Request with Store 1 -> Quote returned
        $reqStore1 = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF', storeId: $store1->id),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );
        $resStore1 = $this->engine->calculateQuotes($reqStore1);
        $this->assertSame(ShippingRateOutcome::SUCCESS, $resStore1->outcome);
        $this->assertCount(1, $resStore1->quotes);
    }
}
