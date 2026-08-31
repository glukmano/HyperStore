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
use Modules\Fulfillment\DTOs\FulfillmentReadiness;
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
        $this->assertSame('source:'.$source->id, $plan->groups[0]->groupKey);

        // Verify zero inventory mutation
        $stock->refresh();
        $this->assertSame('0.0000', $stock->reserved);
        $this->assertSame('10.0000', $stock->on_hand);
    }

    public function test_deterministic_fulfillment_plan_repeatability(): void
    {
        $prod = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'DET-101',
            translations: ['en' => ['name' => 'Deterministic Product']],
        ));

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_DET', 'name' => 'WH DET', 'country_code' => 'CH', 'status' => 'active']);
        $source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_DET', 'name' => 'SRC DET', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $source->id, 'product_id' => $prod->id, 'variant_id' => null, 'on_hand' => '10.0000', 'reserved' => '0.0000', 'backorder_policy' => 'deny']);

        $items = [
            new FulfillmentItemLine(productId: $prod->id, variantId: null, quantity: 2, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('1.0', 'kg'), isShippable: true),
            new FulfillmentItemLine(productId: 999, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(500, 'CHF'), unitWeight: Weight::zero(), isShippable: false),
        ];

        $plan1 = $this->planner->plan($this->tenant->id, $items, new ShippingContext(tenantId: $this->tenant->id));
        $plan2 = $this->planner->plan($this->tenant->id, $items, new ShippingContext(tenantId: $this->tenant->id));

        $this->assertSame($plan1->groups[0]->groupKey, $plan2->groups[0]->groupKey);
        $this->assertSame($plan1->groups[1]->groupKey, $plan2->groups[1]->groupKey);
    }

    public function test_line_quantity_split_across_sources(): void
    {
        $prod = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'SPLIT-101',
            translations: ['en' => ['name' => 'Split Product']],
        ));

        $wh1 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_A', 'name' => 'WH A', 'country_code' => 'CH', 'status' => 'active']);
        $wh2 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_B', 'name' => 'WH B', 'country_code' => 'CH', 'status' => 'active']);

        $src1 = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh1->id, 'code' => 'SRC_A', 'name' => 'Source A', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 20]);
        $src2 = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh2->id, 'code' => 'SRC_B', 'name' => 'Source B', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        // Source 1 has 6, Source 2 has 4
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src1->id, 'product_id' => $prod->id, 'variant_id' => null, 'on_hand' => '6.0000', 'reserved' => '0.0000', 'backorder_policy' => 'deny']);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src2->id, 'product_id' => $prod->id, 'variant_id' => null, 'on_hand' => '4.0000', 'reserved' => '0.0000', 'backorder_policy' => 'deny']);

        // Request 10 units
        $items = [
            new FulfillmentItemLine(productId: $prod->id, variantId: null, quantity: 10, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('1.0', 'kg'), isShippable: true),
        ];

        $plan = $this->planner->plan($this->tenant->id, $items, new ShippingContext(tenantId: $this->tenant->id));

        $this->assertTrue($plan->hasSplits);
        $this->assertCount(2, $plan->groups);

        // Group 1 (Source A, priority 20) gets 6 units
        $groupA = collect($plan->groups)->firstWhere('inventorySourceId', $src1->id);
        $this->assertNotNull($groupA);
        $this->assertSame(6, $groupA->items[0]->quantity);

        // Group 2 (Source B, priority 10) gets 4 units
        $groupB = collect($plan->groups)->firstWhere('inventorySourceId', $src2->id);
        $this->assertNotNull($groupB);
        $this->assertSame(4, $groupB->items[0]->quantity);
    }

    public function test_unavailable_group_when_no_source_has_stock(): void
    {
        $prod = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'UNAVAIL-101',
            translations: ['en' => ['name' => 'Unavail Product']],
        ));

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_ZERO', 'name' => 'WH ZERO', 'country_code' => 'CH', 'status' => 'active']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_ZERO', 'name' => 'SRC ZERO', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $prod->id, 'variant_id' => null, 'on_hand' => '0.0000', 'reserved' => '0.0000', 'backorder_policy' => 'deny']);

        $items = [
            new FulfillmentItemLine(productId: $prod->id, variantId: null, quantity: 5, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('1.0', 'kg'), isShippable: true),
        ];

        $plan = $this->planner->plan($this->tenant->id, $items, new ShippingContext(tenantId: $this->tenant->id));

        $this->assertCount(1, $plan->groups);
        $this->assertSame(FulfillmentReadiness::UNAVAILABLE, $plan->groups[0]->readiness);
        $this->assertFalse($plan->groups[0]->isShippable);
    }
}
