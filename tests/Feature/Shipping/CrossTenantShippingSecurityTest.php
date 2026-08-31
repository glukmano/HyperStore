<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class CrossTenantShippingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
    }

    public function test_tenant_b_cannot_match_or_quote_tenant_a_shipping_zones(): void
    {
        $zoneA = ShippingZone::create(['tenant_id' => $this->tenantA->id, 'code' => 'ZONE_A', 'name' => 'Zone A', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zoneA->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $methodA = ShippingMethod::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'METHOD_A',
            'name' => 'Method A',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodA->id, 'shipping_zone_id' => $zoneA->id]);

        $matcher = app(ShippingZoneMatcherInterface::class);
        $engine = app(ShippingRateEngineInterface::class);

        $dest = new ShippingDestination(countryCode: 'CH');
        $contextB = new ShippingContext(tenantId: $this->tenantB->id);

        // Tenant B matcher must NOT see Tenant A zones
        $matchedB = $matcher->match($dest, $contextB);
        $this->assertEmpty($matchedB);

        // Tenant B quote request must NOT return Tenant A methods
        $reqB = new ShippingRateRequest(
            context: $contextB,
            destination: $dest,
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );
        $quotesB = $engine->calculateQuotes($reqB);
        $this->assertEmpty($quotesB);
    }
}
