<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class FulfillmentPlanningAndSplitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private FulfillmentPlanningServiceInterface $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Fulfillment Tenant', 'slug' => 'fulf-test', 'status' => 'active']);
        $this->planner = app(FulfillmentPlanningServiceInterface::class);
    }

    public function test_planning_is_pure_and_causes_zero_inventory_reservations(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'FULF-PROD-200',
            translations: ['en' => ['name' => 'Fulfillment Physical Product']],
        ));

        $warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'WH_MAIN',
            'name' => 'Main WH',
            'country_code' => 'CH',
            'status' => 'active',
        ]);

        $source = InventorySource::create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $warehouse->id,
            'code' => 'SRC_MAIN',
            'name' => 'Main Source',
            'source_type' => 'warehouse',
            'status' => 'active',
            'priority' => 10,
        ]);

        $stock = StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $source->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'on_hand' => '10.0000',
            'reserved' => '0.0000',
            'backorder_policy' => 'deny',
        ]);

        $items = [
            new FulfillmentItemLine(
                productId: $product->id,
                variantId: null,
                quantity: 2,
                unitPrice: MoneyValue::fromMinor(5000, 'CHF'),
                unitWeight: Weight::of('1.0000', 'kg'),
                isShippable: true
            ),
        ];

        $plan = $this->planner->plan($this->tenant->id, $items, new ShippingContext(tenantId: $this->tenant->id));

        $this->assertFalse($plan->hasSplits);
        $this->assertCount(1, $plan->groups);
        $this->assertSame($source->id, $plan->groups[0]->inventorySourceId);

        // Verify zero inventory reservation mutation
        $stock->refresh();
        $this->assertSame('0.0000', $stock->reserved);
        $this->assertSame('10.0000', $stock->on_hand);
    }

    public function test_mixed_physical_and_digital_request_creates_non_physical_group(): void
    {
        $prodPhys = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'FULF-PHYS-101',
            translations: ['en' => ['name' => 'Physical Product']],
        ));

        $warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'WH_1',
            'name' => 'WH 1',
            'country_code' => 'CH',
            'status' => 'active',
        ]);

        $source = InventorySource::create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $warehouse->id,
            'code' => 'SRC_1',
            'name' => 'Source 1',
            'source_type' => 'warehouse',
            'status' => 'active',
            'priority' => 10,
        ]);

        StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $source->id,
            'product_id' => $prodPhys->id,
            'variant_id' => null,
            'on_hand' => '5.0000',
            'reserved' => '0.0000',
            'backorder_policy' => 'deny',
        ]);

        $items = [
            // Physical item
            new FulfillmentItemLine(productId: $prodPhys->id, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(2000, 'CHF'), unitWeight: Weight::of('0.5', 'kg'), isShippable: true),
            // Digital e-book
            new FulfillmentItemLine(productId: 999, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(990, 'CHF'), unitWeight: Weight::zero(), isShippable: false),
        ];

        $plan = $this->planner->plan($this->tenant->id, $items, new ShippingContext(tenantId: $this->tenant->id));

        $this->assertCount(2, $plan->groups);
        $shippableGroup = collect($plan->groups)->firstWhere('isShippable', true);
        $digitalGroup = collect($plan->groups)->firstWhere('isShippable', false);

        $this->assertNotNull($shippableGroup);
        $this->assertNotNull($digitalGroup);
        $this->assertSame('non_physical', $digitalGroup->fulfillmentMode);
    }
}
