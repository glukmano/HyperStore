<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Customers\Notifications\BackInStockDetected;
use Modules\Customers\Services\AlertSubscriptionService;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;
use Tests\TestCase;

/**
 * Proves back-in-stock alerts fire only on the exact <=0 -> >0
 * available-to-sell transition (Modules\Inventory\Events\StockReplenished,
 * new in Phase-17) — never on every stock adjustment the way
 * LowStockDetected/OutOfStockDetected do — and that the notify decision is
 * re-verified against the real InventoryAvailabilityService per subscription
 * rather than trusting the raw event alone.
 */
class BackInStockAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    private Product $product;

    private StockItem $stockItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'stock-alert-tenant', 'name' => 'Stock Alert Tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'stock-alert-store', 'status' => 'active']);
        $this->user = User::create(['name' => 'Cust', 'email' => 'cust-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'STOCK-ALERT-SKU-1',
            translations: ['en' => ['name' => 'Stock Alert Product']],
        ));

        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'ALERT-WH-1', 'name' => 'Alert Wh', 'country_code' => 'US']);
        $source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'ALERT-SRC-1', 'name' => 'Alert Src']);

        $this->stockItem = StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $source->id,
            'product_id' => $this->product->id,
            'on_hand' => '0.0000',
        ]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
    }

    public function test_replenishing_out_of_stock_inventory_notifies_the_subscriber(): void
    {
        Notification::fake();

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToBackInStock($this->user, $this->product->id, null, $this->store->id);

        app(InventoryAdjustmentServiceInterface::class)->receive($this->stockItem, Quantity::fromString('10.0000'));

        Notification::assertSentTo($this->user, BackInStockDetected::class);
    }

    public function test_adjusting_stock_that_was_already_in_stock_does_not_notify_again(): void
    {
        Notification::fake();

        // Already in stock before the subscription exists.
        app(InventoryAdjustmentServiceInterface::class)->receive($this->stockItem, Quantity::fromString('10.0000'));

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToBackInStock($this->user, $this->product->id, null, $this->store->id);

        // A further adjustment that stays positive is not a "back in stock"
        // transition — LowStockDetected/OutOfStockDetected-style level checks
        // would fire on every call; StockReplenished must not.
        app(InventoryAdjustmentServiceInterface::class)->receive($this->stockItem, Quantity::fromString('5.0000'));

        Notification::assertNothingSentTo($this->user);
    }

    public function test_the_subscription_is_a_one_shot_alert_and_does_not_notify_twice(): void
    {
        Notification::fake();

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToBackInStock($this->user, $this->product->id, null, $this->store->id);

        app(InventoryAdjustmentServiceInterface::class)->receive($this->stockItem, Quantity::fromString('10.0000'));
        app(InventoryAdjustmentServiceInterface::class)->adjust($this->stockItem->fresh(), Quantity::fromString('-10.0000'), 'adjustment_out');
        app(InventoryAdjustmentServiceInterface::class)->receive($this->stockItem->fresh(), Quantity::fromString('10.0000'));

        Notification::assertSentToTimes($this->user, BackInStockDetected::class, 1);
    }

    public function test_going_out_of_stock_then_back_in_stock_fires_a_fresh_transition(): void
    {
        Notification::fake();

        app(InventoryAdjustmentServiceInterface::class)->receive($this->stockItem, Quantity::fromString('10.0000'));

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToBackInStock($this->user, $this->product->id, null, $this->store->id);

        app(InventoryAdjustmentServiceInterface::class)->adjust($this->stockItem->fresh(), Quantity::fromString('-10.0000'), 'adjustment_out');
        Notification::assertNothingSentTo($this->user);

        app(InventoryAdjustmentServiceInterface::class)->receive($this->stockItem->fresh(), Quantity::fromString('10.0000'));
        Notification::assertSentTo($this->user, BackInStockDetected::class);
    }
}
