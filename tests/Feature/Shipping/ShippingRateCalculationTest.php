<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\FreeShippingBenefitDTO;
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

        $quotes = $this->engine->calculateQuotes($request);

        $this->assertCount(2, $quotes);
        // Priority 10 comes FIRST even though amount is higher
        $this->assertSame('EXPRESS_HIGH_PRIO', $quotes[0]->methodCode);
        $this->assertSame('STANDARD_LOW_PRIO', $quotes[1]->methodCode);
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

        $quotes = $this->engine->calculateQuotes($request);

        $this->assertCount(1, $quotes);
        $this->assertSame(0, $quotes[0]->amount->getMinorAmount());
        $this->assertSame(1700, $quotes[0]->breakdown->promotionDiscount->getMinorAmount());
        $this->assertSame(1500, $quotes[0]->breakdown->baseRate->getMinorAmount());
        $this->assertSame(200, $quotes[0]->breakdown->handlingFee->getMinorAmount());
    }
}
