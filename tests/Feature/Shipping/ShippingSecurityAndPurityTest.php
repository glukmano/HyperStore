<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\Warehouse;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\PickupLocation;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\Services\CarrierCredentialService;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class ShippingSecurityAndPurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);

        $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->adminA = User::create([
            'name' => 'Admin A',
            'email' => 'admin_a@hyperstore.ch',
            'password' => bcrypt('secret123'),
        ]);
        $this->adminA->givePermissionTo('shipping.rates.quote');
    }

    public function test_shipping_rate_quote_is_pure_and_mutates_zero_tables(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenantA->id, 'code' => 'CH_PURITY', 'name' => 'CH Purity', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $method = ShippingMethod::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'PURITY_METHOD',
            'name' => 'Purity Method',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenantA->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::of('1.0', 'kg'), 'is_shippable' => true]]
        );

        $tablesToCheck = ['shipping_zones', 'shipping_methods', 'stock_items', 'inventory_movements', 'inventory_reservations'];
        $countsBefore = [];
        foreach ($tablesToCheck as $table) {
            $countsBefore[$table] = DB::table($table)->count();
        }

        $engine = app(ShippingRateEngineInterface::class);
        $quotes = $engine->calculateQuotes($request);

        $this->assertCount(1, $quotes);

        foreach ($tablesToCheck as $table) {
            $this->assertSame($countsBefore[$table], DB::table($table)->count(), "Table [{$table}] was mutated during quote calculation!");
        }
    }

    public function test_carrier_credentials_are_encrypted_and_hidden_from_serialization(): void
    {
        $carrier = Carrier::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'DHL_SEC',
            'name' => 'DHL Secure',
            'provider_code' => 'manual',
            'status' => 'active',
        ]);

        $credService = app(CarrierCredentialService::class);
        $cred = $credService->store($carrier, 'production', [
            'api_key' => 'SECRET_KEY_12345',
            'api_secret' => 'TOP_SECRET_ABCDE',
        ]);

        // 1. Raw DB column contains encrypted ciphertext (not plaintext)
        $rawRow = DB::table('carrier_credentials')->where('id', $cred->id)->first();
        $this->assertNotNull($rawRow);
        $this->assertStringNotContainsString('SECRET_KEY_12345', (string) $rawRow->encrypted_credentials);
        $this->assertStringNotContainsString('TOP_SECRET_ABCDE', (string) $rawRow->encrypted_credentials);

        // 2. Model serialization (toArray / toJson) excludes the encrypted_credentials attribute
        $array = $cred->toArray();
        $this->assertArrayNotHasKey('encrypted_credentials', $array);
        $this->assertStringNotContainsString('SECRET_KEY_12345', json_encode($array));

        // 3. Decryption returns original payload
        $decrypted = $credService->getDecrypted($cred);
        $this->assertSame('SECRET_KEY_12345', $decrypted['api_key']);
        $this->assertSame('TOP_SECRET_ABCDE', $decrypted['api_secret']);
    }

    public function test_api_rejects_cross_tenant_product_ownership(): void
    {
        // Product belongs to Tenant B
        $prodB = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenantB->id,
            productType: 'physical',
            sku: 'PROD-TENANT-B',
            translations: ['en' => ['name' => 'Product B']],
        ));

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenantA->id, $this->tenantA->name));

        $response = $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/shipping/rates/quote', [
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $prodB->id, 'quantity' => 1, 'unit_price' => 1000],
            ],
        ]);

        // Must reject cross-tenant product
        $response->assertStatus(404);
    }

    public function test_api_rejects_cross_tenant_inventory_source_idor(): void
    {
        // Product belongs to Tenant A
        $prodA = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenantA->id,
            productType: 'physical',
            sku: 'PROD-TENANT-A',
            translations: ['en' => ['name' => 'Product A']],
        ));

        // Source belongs to Tenant B
        $whB = Warehouse::create(['tenant_id' => $this->tenantB->id, 'code' => 'WH_B', 'name' => 'WH B', 'country_code' => 'CH', 'status' => 'active']);
        $srcB = InventorySource::create(['tenant_id' => $this->tenantB->id, 'warehouse_id' => $whB->id, 'code' => 'SRC_B', 'name' => 'SRC B', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenantA->id, $this->tenantA->name));

        $response = $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/shipping/rates/quote', [
            'destination' => ['country_code' => 'CH'],
            'lines' => [
                ['product_id' => $prodA->id, 'quantity' => 1, 'unit_price' => 1000, 'inventory_source_id' => $srcB->id],
            ],
        ]);

        // Must reject cross-tenant inventory source
        $response->assertStatus(404);
    }

    public function test_cross_tenant_method_zone_relationship_is_rejected(): void
    {
        $zoneA = ShippingZone::create(['tenant_id' => $this->tenantA->id, 'code' => 'ZONE_A', 'name' => 'Zone A', 'status' => 'active']);
        $methodB = ShippingMethod::create([
            'tenant_id' => $this->tenantB->id,
            'code' => 'METHOD_B',
            'name' => 'Method B',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        ShippingMethodZone::create([
            'shipping_method_id' => $methodB->id,
            'shipping_zone_id' => $zoneA->id,
        ]);
    }

    public function test_cross_tenant_pickup_location_source_is_rejected(): void
    {
        $whB = Warehouse::create(['tenant_id' => $this->tenantB->id, 'code' => 'WH_B_SEC', 'name' => 'WH B Sec', 'country_code' => 'CH', 'status' => 'active']);
        $srcB = InventorySource::create(['tenant_id' => $this->tenantB->id, 'warehouse_id' => $whB->id, 'code' => 'SRC_B_SEC', 'name' => 'SRC B Sec', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        $this->expectException(\InvalidArgumentException::class);
        PickupLocation::create([
            'tenant_id' => $this->tenantA->id,
            'inventory_source_id' => $srcB->id,
            'code' => 'PICKUP_CROSS',
            'name' => 'Pickup Cross',
            'status' => 'active',
        ]);
    }
}
