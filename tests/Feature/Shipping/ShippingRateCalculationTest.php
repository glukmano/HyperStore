<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class ShippingRateCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ShippingRateEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Rate Engine Tenant', 'slug' => 'rate-engine', 'status' => 'active']);
        $this->engine = app(ShippingRateEngineInterface::class);
    }

    public function test_quote_calculation_is_pure_and_has_zero_side_effects(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STD_CH',
            'name' => 'Standard Post',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 850, // 8.50 CHF
            'handling_fee' => 150, // 1.50 CHF
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH', postalCode: '8001'),
            lines: [
                [
                    'product_id' => 100,
                    'variant_id' => null,
                    'quantity' => 2,
                    'unit_price' => MoneyValue::fromMinor(2500, 'CHF'),
                    'unit_weight' => Weight::of('0.5000', 'kg'),
                    'dimensions' => null,
                    'shipping_class_id' => null,
                    'is_shippable' => true,
                    'inventory_source_id' => null,
                ],
            ]
        );

        // Snapshot table counts before quote
        $zoneCountBefore = DB::table('shipping_zones')->count();
        $methodCountBefore = DB::table('shipping_methods')->count();

        $quotes = $this->engine->calculateQuotes($request);

        // Verify quote results
        $this->assertCount(1, $quotes);
        $quote = $quotes->first();
        $this->assertSame('STD_CH', $quote->methodCode);
        $this->assertSame(1000, $quote->amount->getMinorAmount()); // 850 + 150 = 1000 (10.00 CHF)
        $this->assertSame(850, $quote->breakdown->baseRate->getMinorAmount());
        $this->assertSame(150, $quote->breakdown->handlingFee->getMinorAmount());

        // Assert zero DB mutations
        $this->assertSame($zoneCountBefore, DB::table('shipping_zones')->count());
        $this->assertSame($methodCountBefore, DB::table('shipping_methods')->count());
    }

    public function test_free_shipping_calculator_subtotal_threshold(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_FREE', 'name' => 'CH Free', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $freeMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FREE_OVER_50',
            'name' => 'Free Shipping over 50 CHF',
            'rate_calculator_type' => 'free_shipping',
            'currency' => 'CHF',
            'base_amount' => 0,
            'handling_fee' => 0,
            'min_subtotal' => 5000, // 50.00 CHF min
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $freeMethod->id, 'shipping_zone_id' => $zone->id]);

        // Request with 30 CHF subtotal -> ineligible
        $reqLow = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(3000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );
        $quotesLow = $this->engine->calculateQuotes($reqLow);
        $this->assertEmpty($quotesLow);

        // Request with 60 CHF subtotal -> eligible
        $reqHigh = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 2, 'unit_price' => MoneyValue::fromMinor(3000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );
        $quotesHigh = $this->engine->calculateQuotes($reqHigh);
        $this->assertCount(1, $quotesHigh);
        $this->assertSame(0, $quotesHigh->first()?->amount->getMinorAmount());
    }
}
