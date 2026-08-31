<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingRestriction;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class PromotionFreeShippingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ShippingRateEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Promo Shipping Tenant', 'slug' => 'promo-ship', 'status' => 'active']);
        $this->engine = app(ShippingRateEngineInterface::class);
    }

    public function test_promotion_free_shipping_benefit_waives_eligible_method_rate(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_PROMO', 'name' => 'CH Promo', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'EXPRESS_CH',
            'name' => 'Express Post',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1500, // 15.00 CHF
            'handling_fee' => 200, // 2.00 CHF
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        $requestWithPromo = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(4000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ],
            promotionBenefits: [
                ['type' => 'free_shipping', 'promotion_id' => 42],
            ]
        );

        $quotes = $this->engine->calculateQuotes($requestWithPromo);
        $this->assertCount(1, $quotes);

        $quote = $quotes->first();
        $this->assertSame(0, $quote->amount->getMinorAmount());
        $this->assertSame(1700, $quote->breakdown->promotionDiscount->getMinorAmount());
        $this->assertSame(1500, $quote->breakdown->baseRate->getMinorAmount());
        $this->assertSame(200, $quote->breakdown->handlingFee->getMinorAmount());
    }

    public function test_free_shipping_benefit_cannot_bypass_restrictions(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_RESTRICT', 'name' => 'CH Restrict', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'RESTRICTED_METHOD',
            'name' => 'Restricted Method',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        // Add restriction
        ShippingRestriction::create([
            'tenant_id' => $this->tenant->id,
            'restriction_type' => 'method_zone',
            'shipping_method_id' => $method->id,
            'shipping_zone_id' => $zone->id,
            'reason' => 'Prohibited',
        ]);

        $requestWithPromo = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(4000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ],
            promotionBenefits: [
                ['type' => 'free_shipping'],
            ]
        );

        $quotes = $this->engine->calculateQuotes($requestWithPromo);
        $this->assertEmpty($quotes); // Method remains restricted despite free shipping promotion
    }
}
