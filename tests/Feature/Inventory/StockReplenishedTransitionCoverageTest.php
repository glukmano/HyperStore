<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Customers\Notifications\BackInStockDetected;
use Modules\Customers\Services\AlertSubscriptionService;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Events\StockReplenished;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;
use Tests\TestCase;

/**
 * Phase-17 completion delta §5 — every mutation path capable of crossing
 * the available-to-sell <=0 -> >0 edge must dispatch StockReplenished, not
 * only InventoryAdjustmentService::adjust(). Covers the four gaps found by
 * source audit: reservation release, reservation expiry, transfer receiving,
 * and quarantine release.
 */
class StockReplenishedTransitionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'ats-coverage-tenant', 'name' => 'ATS Coverage Tenant', 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
    }

    public function test_releasing_a_reservation_that_fully_consumed_available_stock_fires_stock_replenished(): void
    {
        Event::fake([StockReplenished::class]);

        $product = $this->createProduct('ATS-REL-SKU');
        $source = $this->createSource();

        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $source->id,
            'product_id' => $product->id,
            'on_hand' => '5.0000',
        ]);

        $reservationService = app(InventoryReservationServiceInterface::class);
        $result = $reservationService->reserve(
            $this->tenant->id, 'ats-rel-key-1', $product->id, null,
            Quantity::fromString('5.0000'), new InventoryContext(tenantId: $this->tenant->id),
        );
        $this->assertTrue($result->isSuccess);

        // Fully reserved: ATS is now 0. Releasing restores it to positive.
        Event::assertNotDispatched(StockReplenished::class);

        $released = $reservationService->release($this->tenant->id, 'ats-rel-key-1');
        $this->assertTrue($released);

        Event::assertDispatched(StockReplenished::class, fn (StockReplenished $e) => $e->previousAvailableQty === '0.0000' && (float) $e->newAvailableQty === 5.0);
    }

    public function test_releasing_a_reservation_that_never_exhausted_stock_does_not_fire_stock_replenished(): void
    {
        Event::fake([StockReplenished::class]);

        $product = $this->createProduct('ATS-REL-SKU-2');
        $source = $this->createSource();

        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $source->id,
            'product_id' => $product->id,
            'on_hand' => '10.0000',
        ]);

        $reservationService = app(InventoryReservationServiceInterface::class);
        $reservationService->reserve(
            $this->tenant->id, 'ats-rel-key-2', $product->id, null,
            Quantity::fromString('3.0000'), new InventoryContext(tenantId: $this->tenant->id),
        );

        // ATS was 7 before release (never <=0) — releasing must not fire.
        $reservationService->release($this->tenant->id, 'ats-rel-key-2');

        Event::assertNotDispatched(StockReplenished::class);
    }

    public function test_reservation_expiry_that_restores_availability_fires_stock_replenished_and_notifies_subscriber(): void
    {
        Notification::fake();

        $product = $this->createProduct('ATS-EXPIRE-SKU');
        $source = $this->createSource();
        $warehouse = Warehouse::find($source->warehouse_id);

        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $source->id,
            'product_id' => $product->id,
            'on_hand' => '4.0000',
        ]);

        $user = User::create(['name' => 'Cust', 'email' => 'cust-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'ats-expire-store-'.uniqid(), 'status' => 'active']);
        app(AlertSubscriptionService::class)->subscribeToBackInStock($user, $product->id, null, $store->id);

        $reservationService = app(InventoryReservationServiceInterface::class);
        $result = $reservationService->reserve(
            $this->tenant->id, 'ats-expire-key-1', $product->id, null,
            Quantity::fromString('4.0000'), new InventoryContext(tenantId: $this->tenant->id),
            ttlMinutes: -1, // already expired
        );
        $this->assertTrue($result->isSuccess);

        $reservation = $result->reservation;
        $this->assertNotNull($reservation);

        $expired = $reservationService->expire($reservation->fresh());
        $this->assertTrue($expired);

        Notification::assertSentTo($user, BackInStockDetected::class);
    }

    public function test_transfer_receiving_that_restores_availability_at_the_destination_fires_stock_replenished(): void
    {
        Event::fake([StockReplenished::class]);

        $product = $this->createProduct('ATS-TRANSFER-SKU');

        $sourceWh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'ATS-TR-SRC-WH', 'name' => 'Source Wh', 'country_code' => 'CH']);
        $destWh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'ATS-TR-DEST-WH', 'name' => 'Dest Wh', 'country_code' => 'CH']);
        $sourceSrc = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $sourceWh->id, 'code' => 'ATS-TR-SRC-S', 'name' => 'Source S']);
        $destSrc = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $destWh->id, 'code' => 'ATS-TR-DEST-S', 'name' => 'Dest S']);

        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $sourceSrc->id, 'product_id' => $product->id, 'on_hand' => '20.0000']);

        // Destination starts with zero stock — receiving is a genuine 0 -> positive transition.
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $destSrc->id, 'product_id' => $product->id, 'on_hand' => '0.0000']);

        $transfer = InventoryTransfer::create([
            'tenant_id' => $this->tenant->id,
            'transfer_number' => 'ATS-TR-001',
            'source_inventory_source_id' => $sourceSrc->id,
            'destination_inventory_source_id' => $destSrc->id,
            'source_warehouse_id' => $sourceWh->id,
            'destination_warehouse_id' => $destWh->id,
            'status' => 'requested',
        ]);

        $item = InventoryTransferItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'requested_quantity' => '10.0000',
        ]);

        $transferService = app(InventoryTransferServiceInterface::class);
        $transferService->dispatch($transfer);

        Event::assertNotDispatched(StockReplenished::class);

        $transferService->receive($transfer, [$item->id => '10.0000']);

        Event::assertDispatched(StockReplenished::class, fn (StockReplenished $e) => $e->previousAvailableQty === '0.0000' && (float) $e->newAvailableQty === 10.0);
    }

    public function test_releasing_quarantine_that_restores_availability_fires_stock_replenished(): void
    {
        Event::fake([StockReplenished::class]);

        $product = $this->createProduct('ATS-QUARANTINE-SKU');
        $source = $this->createSource();

        $stockItem = StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $source->id,
            'product_id' => $product->id,
            'on_hand' => '5.0000',
            'quarantined' => '0.0000',
        ]);

        $adjustmentService = app(InventoryAdjustmentServiceInterface::class);

        // Quarantining the entire on-hand quantity drives ATS to zero.
        $adjustmentService->quarantine($stockItem, Quantity::fromString('5.0000'));
        Event::assertNotDispatched(StockReplenished::class);

        $adjustmentService->releaseQuarantine($stockItem->fresh(), Quantity::fromString('5.0000'));

        Event::assertDispatched(StockReplenished::class, fn (StockReplenished $e) => $e->previousAvailableQty === '0.0000' && (float) $e->newAvailableQty === 5.0);
    }

    private function createProduct(string $sku): Product
    {
        return app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: $sku,
            translations: ['en' => ['name' => $sku]],
        ));
    }

    private function createSource(): InventorySource
    {
        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'ATS-WH-'.uniqid(), 'name' => 'ATS Wh', 'country_code' => 'CH']);

        return InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'ATS-SRC-'.uniqid(), 'name' => 'ATS Src']);
    }
}
