<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventorySourceQueryInterface;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Calculators\LocalPickupCalculator;
use Modules\Shipping\Models\PickupLocation;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class LocalPickupAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LocalPickupCalculator $pickupCalculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Pickup Tenant', 'slug' => 'pickup-test', 'status' => 'active']);
        $this->pickupCalculator = new LocalPickupCalculator(app(InventorySourceQueryInterface::class));
    }

    public function test_pickup_quote_succeeds_when_stock_is_available_at_location(): void
    {
        $prod = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'PICKUP-101',
            translations: ['en' => ['name' => 'Pickup Product']],
        ));

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_STORE_1', 'name' => 'Store 1 WH', 'country_code' => 'CH', 'status' => 'active']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_STORE_1', 'name' => 'Store 1 Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $prod->id, 'variant_id' => null, 'on_hand' => '5.0000', 'reserved' => '0.0000', 'backorder_policy' => 'deny']);

        $location = PickupLocation::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $src->id,
            'code' => 'ZURICH_STORE',
            'name' => 'Zurich Store Pickup',
            'address_line1' => 'Bahnhofstrasse 1',
            'city' => 'Zurich',
            'postal_code' => '8001',
            'country_code' => 'CH',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_PICKUP', 'name' => 'CH', 'status' => 'active']);
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PICKUP_FREE',
            'name' => 'Free Store Pickup',
            'rate_calculator_type' => 'local_pickup',
            'currency' => 'CHF',
            'base_amount' => 0,
            'handling_fee' => 0,
            'status' => 'active',
            'metadata' => ['pickup_location_id' => $location->id],
        ]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH', city: 'Zurich', postalCode: '8001'),
            lines: [['product_id' => $prod->id, 'variant_id' => null, 'quantity' => 2, 'unit_price' => MoneyValue::fromMinor(2000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );

        $res = $this->pickupCalculator->calculate($method, $zone, $request);
        $this->assertNotNull($res);
        $this->assertSame(0, $res->finalAmount->getMinorAmount());
    }

    public function test_pickup_quote_fails_when_stock_is_insufficient_at_location(): void
    {
        $prod = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'PICKUP-NO-STOCK',
            translations: ['en' => ['name' => 'No Stock Product']],
        ));

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_STORE_2', 'name' => 'Store 2 WH', 'country_code' => 'CH', 'status' => 'active']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_STORE_2', 'name' => 'Store 2 Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $prod->id, 'variant_id' => null, 'on_hand' => '0.0000', 'reserved' => '0.0000', 'backorder_policy' => 'deny']);

        $location = PickupLocation::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $src->id,
            'code' => 'GENEVA_STORE',
            'name' => 'Geneva Store Pickup',
            'address_line1' => 'Rue du Rhone 1',
            'city' => 'Geneva',
            'postal_code' => '1204',
            'country_code' => 'CH',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_PICKUP_2', 'name' => 'CH', 'status' => 'active']);
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PICKUP_GENEVA',
            'name' => 'Geneva Store Pickup',
            'rate_calculator_type' => 'local_pickup',
            'currency' => 'CHF',
            'base_amount' => 0,
            'handling_fee' => 0,
            'status' => 'active',
            'metadata' => ['pickup_location_id' => $location->id],
        ]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH', city: 'Geneva', postalCode: '1204'),
            lines: [['product_id' => $prod->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(2000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );

        $res = $this->pickupCalculator->calculate($method, $zone, $request);
        $this->assertNull($res); // Ineligible because location has 0 stock
    }
}
