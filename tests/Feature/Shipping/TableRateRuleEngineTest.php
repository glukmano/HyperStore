<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingRateRule;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class TableRateRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ShippingRateEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Table Rate Tenant', 'slug' => 'tbl-rate', 'status' => 'active']);
        $this->engine = app(ShippingRateEngineInterface::class);
    }

    public function test_table_rate_weight_and_subtotal_tier_evaluations(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_TBL', 'name' => 'CH Table', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TABLE_METHOD',
            'name' => 'Table Method',
            'rate_calculator_type' => 'table_rate',
            'currency' => 'CHF',
            'base_amount' => 500, // 5.00 CHF base
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        // Tier 1: weight <= 2kg -> fee = 200
        ShippingRateRule::create([
            'shipping_method_id' => $method->id,
            'priority' => 10,
            'condition_type' => 'weight_range',
            'conditions_payload' => ['max_weight' => '2.0000'],
            'action_type' => 'fixed_amount',
            'action_payload' => ['amount' => 200],
            'stop_processing' => true,
        ]);

        // Tier 2: weight > 2kg -> fee = 800
        ShippingRateRule::create([
            'shipping_method_id' => $method->id,
            'priority' => 5,
            'condition_type' => 'weight_range',
            'conditions_payload' => ['min_weight' => '2.0001'],
            'action_type' => 'fixed_amount',
            'action_payload' => ['amount' => 800],
            'stop_processing' => true,
        ]);

        // Light parcel (1kg) -> 500 base + 200 fee = 700
        $reqLight = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::of('1.0', 'kg'), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );
        $quoteLight = $this->engine->calculateQuotes($reqLight)->first();
        $this->assertSame(700, $quoteLight?->amount->getMinorAmount());

        // Heavy parcel (3kg) -> 500 base + 800 fee = 1300
        $reqHeavy = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 3, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::of('1.0', 'kg'), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );
        $quoteHeavy = $this->engine->calculateQuotes($reqHeavy)->first();
        $this->assertSame(1300, $quoteHeavy?->amount->getMinorAmount());
    }
}
