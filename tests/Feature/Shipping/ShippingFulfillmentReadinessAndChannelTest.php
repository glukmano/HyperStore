<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Channels\Models\Channel;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneAssignment;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Tests\TestCase;

class ShippingFulfillmentReadinessAndChannelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private ShippingZone $zone;

    private ShippingMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Readiness Tenant', 'slug' => 'readiness-tenant', 'status' => 'active']);
        $this->user = User::create([
            'name' => 'Readiness Admin',
            'email' => 'admin@readiness.ch',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $this->zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE_READ', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $this->zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $this->method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FLAT_READ',
            'name' => 'Flat Read',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->method->id, 'shipping_zone_id' => $this->zone->id]);
    }

    public function test_api_derives_unfulfillable_items_when_physical_inventory_is_unavailable(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.rates.quote');

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'UNAVAIL-SKU-1',
            translations: ['en' => ['name' => 'Unavail Physical Prod']],
        ));

        // Warehouse & source with ZERO stock and backorder denied
        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_ZERO_1', 'name' => 'WH Zero', 'country_code' => 'CH', 'status' => 'active']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_ZERO_1', 'name' => 'SRC Zero', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $src->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'on_hand' => '0.0000',
            'reserved' => '0.0000',
            'backorder_mode' => 'deny',
            'tracking_mode' => 'tracked',
        ]);

        // Caller attempts to force has_unfulfillable_items = false to trick the engine
        $res = $this->postJson('/api/v1/shipping/rates/quote', [
            'currency' => 'CHF',
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5000, 'unit_weight' => '1.0'],
            ],
            'has_unfulfillable_items' => false, // MUST BE IGNORED
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $this->assertSame(ShippingRateOutcome::UNFULFILLABLE_ITEMS, $res->json('outcome'));
        $this->assertFalse($res->json('is_success'));
        $this->assertEmpty($res->json('quotes'));
    }

    public function test_api_derives_success_when_physical_inventory_is_available(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.rates.quote');

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'AVAIL-SKU-1',
            translations: ['en' => ['name' => 'Avail Physical Prod']],
        ));

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_AVAIL_1', 'name' => 'WH Avail', 'country_code' => 'CH', 'status' => 'active']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_AVAIL_1', 'name' => 'SRC Avail', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $src->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'on_hand' => '10.0000',
            'reserved' => '0.0000',
            'backorder_mode' => 'deny',
            'tracking_mode' => 'tracked',
        ]);

        // Caller attempts to force has_unfulfillable_items = true
        $res = $this->postJson('/api/v1/shipping/rates/quote', [
            'currency' => 'CHF',
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5000, 'unit_weight' => '1.0'],
            ],
            'has_unfulfillable_items' => true, // MUST BE IGNORED
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $this->assertSame(ShippingRateOutcome::SUCCESS, $res->json('outcome'));
        $this->assertTrue($res->json('is_success'));
        $this->assertCount(1, $res->json('quotes'));
    }

    public function test_api_derives_success_when_inventory_is_backorderable(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.rates.quote');

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'BACKORDER-SKU-1',
            translations: ['en' => ['name' => 'Backorderable Prod']],
        ));

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_BO_1', 'name' => 'WH BO', 'country_code' => 'CH', 'status' => 'active']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_BO_1', 'name' => 'SRC BO', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        // Zero on hand, but backorder_mode = allow
        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $src->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'on_hand' => '0.0000',
            'reserved' => '0.0000',
            'backorder_mode' => 'allow',
            'tracking_mode' => 'tracked',
        ]);

        $res = $this->postJson('/api/v1/shipping/rates/quote', [
            'currency' => 'CHF',
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 5000, 'unit_weight' => '1.0'],
            ],
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $this->assertSame(ShippingRateOutcome::SUCCESS, $res->json('outcome'));
        $this->assertTrue($res->json('is_success'));
        $this->assertCount(1, $res->json('quotes'));
    }

    public function test_api_digital_only_request_never_derives_unfulfillable_items(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.rates.quote');

        $digitalProduct = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'digital',
            sku: 'DIGITAL-SKU-1',
            translations: ['en' => ['name' => 'Digital eBook']],
        ));

        $res = $this->postJson('/api/v1/shipping/rates/quote', [
            'currency' => 'CHF',
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $digitalProduct->id, 'quantity' => 1, 'unit_price' => 2000],
            ],
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $this->assertSame(ShippingRateOutcome::SUCCESS, $res->json('outcome'));
        $this->assertTrue($res->json('is_success'));
    }

    public function test_global_channel_validation_in_zone_assignments(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.zones.manage');

        $activeChannel = Channel::create(['name' => 'Webstore Active', 'handle' => 'webstore-act', 'is_active' => true]);
        $inactiveChannel = Channel::create(['name' => 'POS Inactive', 'handle' => 'pos-inact', 'is_active' => false]);

        // 1. Assigning active channel succeeds
        $resActive = $this->postJson("/api/v1/shipping/zones/{$this->zone->id}/assignments", [
            'channel_id' => $activeChannel->id,
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);
        $resActive->assertStatus(201);

        // 2. Assigning inactive channel fails with 404 (not an active channel)
        $resInactive = $this->postJson("/api/v1/shipping/zones/{$this->zone->id}/assignments", [
            'channel_id' => $inactiveChannel->id,
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);
        $resInactive->assertStatus(404);

        // 3. Model domain guard rejects inactive channel
        $this->expectException(InvalidArgumentException::class);
        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'channel_id' => $inactiveChannel->id,
        ]);
    }
}
