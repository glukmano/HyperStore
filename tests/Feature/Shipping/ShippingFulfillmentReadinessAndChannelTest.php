<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\Services\DefaultPackingService;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\CarrierProviderInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneAssignment;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\Registries\CarrierRegistry;
use Modules\Shipping\ValueObjects\CarrierRateResult;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class CountingCarrierProvider implements CarrierProviderInterface
{
    public static int $callCount = 0;

    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        self::$callCount++;

        return [
            new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: 'STD',
                serviceName: 'Standard',
                rateAmount: MoneyValue::fromMinor(1500, $request->context->currency),
                transitDaysMin: 2,
                transitDaysMax: 4
            ),
        ];
    }
}

class ShippingFulfillmentReadinessAndChannelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Store $storeA;

    private Store $storeB;

    private ShippingZone $zone;

    private ShippingMethod $flatMethod;

    private ShippingMethod $weightMethod;

    private ShippingMethod $carrierMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);
        CountingCarrierProvider::$callCount = 0;

        $this->tenant = Tenant::create(['name' => 'Readiness Tenant', 'slug' => 'readiness-tenant', 'status' => 'active']);
        $this->storeA = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'STORE_A', 'name' => 'Store A', 'slug' => 'store-a', 'status' => 'active']);
        $this->storeB = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'STORE_B', 'name' => 'Store B', 'slug' => 'store-b', 'status' => 'active']);

        $this->user = User::create([
            'name' => 'Readiness Admin',
            'email' => 'admin@readiness.ch',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $this->zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE_READ', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $this->zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        // Flat rate method
        $this->flatMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FLAT_READ',
            'name' => 'Flat Read',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->flatMethod->id, 'shipping_zone_id' => $this->zone->id]);

        // Weight-based rate method
        $this->weightMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'WEIGHT_READ',
            'name' => 'Weight Read',
            'rate_calculator_type' => 'weight_based',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
            'metadata' => ['per_kg_fee' => 500],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->weightMethod->id, 'shipping_zone_id' => $this->zone->id]);

        // Carrier calculated method
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('counting_provider', CountingCarrierProvider::class, override: true);

        $carrier = Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'COUNT_CARRIER',
            'name' => 'Counting Carrier',
            'provider_code' => 'counting_provider',
            'status' => 'active',
        ]);

        $this->carrierMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_READ',
            'name' => 'Carrier Read',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'COUNT_CARRIER', 'service_code' => 'STD'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->carrierMethod->id, 'shipping_zone_id' => $this->zone->id]);
    }

    public function test_digital_only_request_returns_no_shipping_required_and_never_invokes_carrier(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.rates.quote');

        $digital = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'digital',
            sku: 'DIGITAL-ONLY-1',
            translations: ['en' => ['name' => 'Digital eBook']],
        ));

        $res = $this->postJson('/api/v1/shipping/rates/quote', [
            'currency' => 'CHF',
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $digital->id, 'quantity' => 5, 'unit_price' => 2000],
            ],
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $this->assertSame(ShippingRateOutcome::NO_SHIPPING_REQUIRED, $res->json('outcome'));
        $this->assertTrue($res->json('is_success'));
        $this->assertEmpty($res->json('quotes'));
        $this->assertSame(0, CountingCarrierProvider::$callCount, 'Carrier provider must NEVER be called for digital-only requests');
    }

    public function test_service_only_request_returns_no_shipping_required(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.rates.quote');

        $service = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'service',
            sku: 'SERVICE-ONLY-1',
            translations: ['en' => ['name' => 'Consulting Service']],
        ));

        $res = $this->postJson('/api/v1/shipping/rates/quote', [
            'currency' => 'CHF',
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 15000],
            ],
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $this->assertSame(ShippingRateOutcome::NO_SHIPPING_REQUIRED, $res->json('outcome'));
        $this->assertTrue($res->json('is_success'));
        $this->assertEmpty($res->json('quotes'));
    }

    public function test_mixed_request_calculates_rate_using_only_physical_portion_ignoring_digital_weight_and_qty(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.rates.quote');

        $physical = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'PHYS-MIX-1',
            translations: ['en' => ['name' => 'Physical Book']],
        ));

        $digital = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'digital',
            sku: 'DIG-MIX-1',
            translations: ['en' => ['name' => 'Digital Addon']],
        ));

        // Warehouse & stock for physical item
        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_MIX_1', 'name' => 'WH Mix', 'country_code' => 'CH', 'status' => 'active']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_MIX_1', 'name' => 'SRC Mix', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $src->id,
            'product_id' => $physical->id,
            'product_variant_id' => null,
            'on_hand' => '10.0000',
            'reserved' => '0.0000',
            'backorder_mode' => 'deny',
            'tracking_mode' => 'tracked',
        ]);

        // Physical line: qty 1, weight 2.0 kg. Weight rate: 1000 base + (2kg * 500) = 2000 minor (20.00 CHF)
        // Digital line: qty 100, unit_weight 50.0 kg (should be completely filtered out!)
        $res = $this->postJson('/api/v1/shipping/rates/quote', [
            'currency' => 'CHF',
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $physical->id, 'quantity' => 1, 'unit_price' => 3000, 'unit_weight' => '2.0'],
                ['product_id' => $digital->id, 'quantity' => 100, 'unit_price' => 500, 'unit_weight' => '50.0'],
            ],
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $this->assertSame(ShippingRateOutcome::SUCCESS, $res->json('outcome'));
        $this->assertTrue($res->json('is_success'));

        $quotes = collect($res->json('quotes'));
        $weightQuote = $quotes->firstWhere('method_code', 'WEIGHT_READ');
        $this->assertNotNull($weightQuote);

        // Exact rate should be 2000 minor (20.00 CHF) for 2kg physical only.
        $this->assertSame(2000, $weightQuote['amount_minor']);
        $this->assertSame(1000, $weightQuote['breakdown']['per_weight_amount']);
    }

    public function test_digital_items_do_not_generate_physical_packages_in_packing_service(): void
    {
        /** @var PackingServiceInterface $packingService */
        $packingService = app(DefaultPackingService::class);

        $physicalLine = new FulfillmentItemLine(
            productId: 1,
            variantId: null,
            quantity: 1,
            unitPrice: MoneyValue::fromMinor(1000, 'CHF'),
            unitWeight: Weight::of('1.5', 'kg'),
            isShippable: true
        );

        $digitalLine = new FulfillmentItemLine(
            productId: 2,
            variantId: null,
            quantity: 50,
            unitPrice: MoneyValue::fromMinor(500, 'CHF'),
            unitWeight: Weight::of('10.0', 'kg'),
            isShippable: false
        );

        // Packing service with both lines
        $packResult = $packingService->pack([$physicalLine, $digitalLine]);

        $packages = is_array($packResult) ? $packResult : $packResult->packages;
        $this->assertCount(1, $packages);

        // Package contains only the physical line
        $itemsInPackage = $packages[0]->items;
        $this->assertCount(1, $itemsInPackage);
        $this->assertSame(1, $itemsInPackage[0]['product_id']);
    }

    public function test_global_channel_store_eligibility_scenarios(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.zones.manage');

        $activeChannel = Channel::create(['name' => 'Webstore App', 'handle' => 'webstore-app', 'is_active' => true]);
        $inactiveChannel = Channel::create(['name' => 'POS Old', 'handle' => 'pos-old', 'is_active' => false]);

        // Enable activeChannel ONLY for storeA (NOT for storeB)
        StoreChannel::create([
            'store_id' => $this->storeA->id,
            'channel_id' => $activeChannel->id,
            'is_active' => true,
        ]);

        // 1. Assigning activeChannel with storeA succeeds (201)
        $resStoreA = $this->postJson("/api/v1/shipping/zones/{$this->zone->id}/assignments", [
            'store_id' => $this->storeA->id,
            'channel_id' => $activeChannel->id,
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);
        $resStoreA->assertStatus(201);

        // 2. Assigning activeChannel with storeB fails (422 Unprocessable - channel not enabled for storeB)
        $resStoreB = $this->postJson("/api/v1/shipping/zones/{$this->zone->id}/assignments", [
            'store_id' => $this->storeB->id,
            'channel_id' => $activeChannel->id,
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);
        $resStoreB->assertStatus(422);

        // 3. Assigning inactiveChannel fails (404 Not Found)
        $resInactive = $this->postJson("/api/v1/shipping/zones/{$this->zone->id}/assignments", [
            'store_id' => $this->storeA->id,
            'channel_id' => $inactiveChannel->id,
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);
        $resInactive->assertStatus(404);

        // 4. Assigning unknown channel fails (404 Not Found)
        $resUnknown = $this->postJson("/api/v1/shipping/zones/{$this->zone->id}/assignments", [
            'store_id' => $this->storeA->id,
            'channel_id' => 999999,
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);
        $resUnknown->assertStatus(404);

        // 5. Model domain guard rejects activeChannel for storeB
        $this->expectException(InvalidArgumentException::class);
        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => $this->storeB->id,
            'channel_id' => $activeChannel->id,
        ]);
    }
}
