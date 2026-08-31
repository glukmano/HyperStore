<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Calculators\TableRateCalculator;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRateRule;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\TableRate\TableRateActionRegistry;
use Modules\Shipping\TableRate\TableRateConditionRegistry;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class TableRateRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TableRateCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Table Rate Tenant', 'slug' => 'table-rate', 'status' => 'active']);
        $this->calculator = new TableRateCalculator(new TableRateConditionRegistry, new TableRateActionRegistry);
    }

    public function test_table_rate_weight_and_subtotal_tier_evaluations(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH', 'status' => 'active']);
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TABLE_METHOD',
            'name' => 'Table Rate Standard',
            'rate_calculator_type' => 'table_rate',
            'currency' => 'CHF',
            'base_amount' => 500, // 5.00 CHF base
            'handling_fee' => 0,
            'status' => 'active',
        ]);

        // Rule 1: 0 - 2kg -> 200 minor
        ShippingRateRule::create([
            'tenant_id' => $this->tenant->id,
            'shipping_method_id' => $method->id,
            'condition_type' => 'weight_range',
            'priority' => 10,
            'conditions_payload' => ['min_weight' => '0.0000', 'max_weight' => '2.0000'],
            'action_type' => 'fixed_amount',
            'action_payload' => ['amount' => 200],
            'stop_processing' => true,
        ]);

        // Rule 2: > 2kg -> 700 minor
        ShippingRateRule::create([
            'tenant_id' => $this->tenant->id,
            'shipping_method_id' => $method->id,
            'condition_type' => 'weight_range',
            'priority' => 5,
            'conditions_payload' => ['min_weight' => '2.0001', 'max_weight' => '10.0000'],
            'action_type' => 'fixed_amount',
            'action_payload' => ['amount' => 700],
            'stop_processing' => true,
        ]);

        $method->load('rateRules');

        // Request 1: 1.5kg -> Base 500 + Rule 200 = 700
        $req1 = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::of('1.5', 'kg'), 'is_shippable' => true]]
        );
        $res1 = $this->calculator->calculate($method, $zone, $req1);
        $this->assertNotNull($res1);
        $this->assertSame(700, $res1->finalAmount->getMinorAmount());

        // Request 2: 3.0kg -> Base 500 + Rule 700 = 1200
        $req2 = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::of('3.0', 'kg'), 'is_shippable' => true]]
        );
        $res2 = $this->calculator->calculate($method, $zone, $req2);
        $this->assertNotNull($res2);
        $this->assertSame(1200, $res2->finalAmount->getMinorAmount());
    }

    public function test_table_rate_per_weight_step_and_per_item_actions(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE_2', 'name' => 'CH 2', 'status' => 'active']);
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STEP_METHOD',
            'name' => 'Step Method',
            'rate_calculator_type' => 'table_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'handling_fee' => 0,
            'status' => 'active',
        ]);

        // Per weight step: 300 minor per 2kg step
        ShippingRateRule::create([
            'tenant_id' => $this->tenant->id,
            'shipping_method_id' => $method->id,
            'condition_type' => 'weight_range',
            'priority' => 10,
            'conditions_payload' => [],
            'action_type' => 'per_weight_step',
            'action_payload' => ['step_amount' => 300, 'step_kg' => '2.0000'],
            'stop_processing' => true,
        ]);

        $method->load('rateRules');

        // Request: 5kg -> 3 steps (ceil(5/2)) * 300 = 900 + 1000 base = 1900
        $req = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::of('5.0', 'kg'), 'is_shippable' => true]]
        );

        $res = $this->calculator->calculate($method, $zone, $req);
        $this->assertNotNull($res);
        $this->assertSame(1900, $res->finalAmount->getMinorAmount());
    }

    public function test_unknown_condition_or_action_type_throws_exception(): void
    {
        $condRegistry = new TableRateConditionRegistry;
        $this->expectException(InvalidArgumentException::class);

        $rule = new ShippingRateRule([
            'conditions_payload' => ['unknown_condition_type' => 123],
        ]);

        $condRegistry->evaluate($rule, [
            'total_items' => 1,
            'total_weight_kg' => '1.0000',
            'subtotal_minor' => 1000,
            'package_count' => 1,
            'request' => new ShippingRateRequest(new ShippingContext(1), new ShippingDestination('CH'), []),
        ]);
    }
}
