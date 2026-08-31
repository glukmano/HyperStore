<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
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

class CarrierTimeoutAndFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Timeout Tenant', 'slug' => 'timeout-tenant', 'status' => 'active']);
    }

    public function test_static_rate_succeeds_even_when_carrier_provider_fails(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('failing_provider', FailingCarrierProvider::class);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_FAIL_TEST', 'name' => 'CH Fail Test', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        // Method 1: Flat rate static method
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
}
