<?php

declare(strict_types=1);

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface;
use Modules\Dropshipping\Contracts\SupplierInvoiceReconciliationServiceInterface;
use Modules\Dropshipping\Contracts\SupplierRoutingEngineInterface;
use Modules\Dropshipping\Enums\PurchaseOrderStatus;
use Modules\Dropshipping\Enums\SupplierInvoiceReconciliationStatus;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierLocation;
use Modules\Dropshipping\Models\SupplierOffer;
use Modules\Dropshipping\Models\SupplierProductVariant;
use Modules\Dropshipping\Models\TenantSupplierAccess;
use Modules\Fulfillment\Contracts\FulfillmentExecutionServiceInterface;
use Modules\Fulfillment\Enums\FulfillmentMode;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerOrder;
use Modules\Pricing\Models\ExchangeRate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->routingEngine = app(SupplierRoutingEngineInterface::class);
    $this->poOrchestrator = app(DropshipOrderOrchestratorInterface::class);
    $this->invoiceService = app(SupplierInvoiceReconciliationServiceInterface::class);
    $this->fulfService = app(FulfillmentExecutionServiceInterface::class);

    $this->tenant = Tenant::create([
        'name' => 'Dropship Tenant',
        'slug' => 'ds-tenant',
        'is_active' => true,
    ]);

    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'DS Store',
        'slug' => 'ds-store',
        'status' => 'active',
        'url' => 'https://ds.example.com',
    ]);

    $this->market = Market::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'DE',
        'name' => 'Germany',
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'de',
        'timezone' => 'Europe/Berlin',
        'is_active' => true,
    ]);

    $this->channel = Channel::create([
        'name' => 'Web Channel',
        'type' => 'website',
        'handle' => 'web-ds',
        'is_active' => true,
    ]);

    // Product & Variant
    $this->product = Product::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'DS T-Shirt',
        'slug' => 'ds-t-shirt',
        'sku' => 'DS-PROD-001',
        'product_type' => 'physical',
        'status' => 'active',
    ]);

    $this->variant = ProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->product->id,
        'sku' => 'DS-TSHIRT-BLK',
        'combination_hash' => 'hash-black-l',
        'status' => 'active',
        'title' => 'Black / L',
        'price_minor' => 3000,
        'cost_minor' => 1500,
        'currency' => 'EUR',
    ]);

    // Supplier 1: Platform Supplier
    $this->platformSupplier = Supplier::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => null,
        'scope_type' => 'platform',
        'name' => 'Global Print Co',
        'code' => 'GLOBAL_PRINT',
        'provider_type' => 'generic_dropship',
        'currency' => 'EUR',
        'is_active' => true,
    ]);

    $this->location1 = SupplierLocation::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => null,
        'supplier_id' => $this->platformSupplier->id,
        'code' => 'EU-CENTRAL',
        'city' => 'Frankfurt',
        'postal_code' => '60311',
        'address_line1' => 'Main St 1',
        'name' => 'Frankfurt Facility',
        'country_code' => 'DE',
        'is_active' => true,
    ]);

    $this->spv1 = SupplierProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->platformSupplier->id,
        'product_id' => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'supplier_sku' => 'GPC-TSHIRT-BLK',
        'canonical_wholesale_cost_minor' => 1200,
        'currency' => 'EUR',
    ]);

    $this->offer1 = SupplierOffer::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => null,
        'supplier_id' => $this->platformSupplier->id,
        'supplier_product_variant_id' => $this->spv1->id,
        'supplier_location_id' => $this->location1->id,
        'location_wholesale_cost_minor' => 1200,
        'currency' => 'EUR',
        'stock_quantity' => '100.00000000',
        'lead_time_days' => 2,
        'is_available' => true,
    ]);

    // Enable platform supplier for tenant
    TenantSupplierAccess::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->platformSupplier->id,
        'is_enabled' => true,
    ]);
});

test('supplier routing engine evaluates candidates and selects cheapest offer with auditable FX', function (): void {
    ExchangeRate::create([
        'tenant_id' => $this->tenant->id,
        'base_currency' => 'USD',
        'target_currency' => 'EUR',
        'rate' => '0.90000000',
        'source' => 'ecb',
        'is_stale' => false,
        'effective_at' => now(),
    ]);
    // Add Supplier 2: USD offer that is more expensive when converted
    $usSupplier = Supplier::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'scope_type' => 'tenant',
        'name' => 'US Apparel',
        'code' => 'US_APP',
        'provider_type' => 'generic_dropship',
        'currency' => 'USD',
        'is_active' => true,
    ]);

    $location2 = SupplierLocation::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $usSupplier->id,
        'code' => 'US-EAST',
        'city' => 'New York',
        'postal_code' => '10001',
        'address_line1' => 'Broadway 1',
        'name' => 'New York Facility',
        'country_code' => 'US',
        'is_active' => true,
    ]);

    $spv2 = SupplierProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $usSupplier->id,
        'product_id' => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'supplier_sku' => 'USA-TSHIRT-BLK',
        'canonical_wholesale_cost_minor' => 2000,
        'currency' => 'USD',
    ]);

    SupplierOffer::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $usSupplier->id,
        'supplier_product_variant_id' => $spv2->id,
        'supplier_location_id' => $location2->id,
        'location_wholesale_cost_minor' => 2000, // $20.00 USD > 12.00 EUR
        'currency' => 'USD',
        'stock_quantity' => '50.00000000',
        'lead_time_days' => 5,
        'is_available' => true,
    ]);

    $routeResult = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: null,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR',
        deliveryCountryCode: 'DE'
    );

    expect($routeResult['candidate_count'])->toBe(2)
        ->and($routeResult['selected_offer'])->not->toBeNull()
        ->and($routeResult['selected_offer']->id)->toBe($this->offer1->id)
        ->and($routeResult['normalized_cost_minor'])->toBe(1200)
        ->and($routeResult['audit_snapshot'])->toHaveKey('all_candidates');
});

test('orchestrates purchase order materialization and invoice reconciliation with discrepancy detection', function (): void {
    // Setup Order and SellerOrder
    $cart = Cart::create([
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'status' => 'active',
    ]);

    $session = CheckoutSession::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'cart_id' => $cart->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'state' => 'ready_for_order',
    ]);

    $order = Order::create([
        'order_number' => 'ORD-DS-001',
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'checkout_id' => $session->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'order_status' => 'placed',
        'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'merchandise_subtotal_minor' => 3000,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'grand_total_minor' => 3000,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'customer_snapshot' => ['email' => 'ds@example.com'],
        'version' => 1,
        'placed_at' => now(),
    ]);

    $orderItem = OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'sku_snapshot' => $this->variant->sku,
        'name_snapshot' => $this->product->name,
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => true,
        'quantity' => '1.00000000',
        'unit_price_minor' => 3000,
        'subtotal_minor' => 3000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 3000,
    ]);

    $sellerOrder = SellerOrder::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'order_id' => $order->id,
        'seller_order_number' => 'ORD-DS-001-PLT',
        'seller_type' => 'platform',
        'vendor_id' => null,
        'commercial_model' => 'platform_as_merchant_of_record',
        'currency' => 'EUR',
        'subtotal_minor' => 3000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'shipping_original_minor' => 0,
        'shipping_discount_minor' => 0,
        'shipping_final_minor' => 0,
        'total_minor' => 3000,
        'commission_total_minor' => 0,
        'status' => 'open',
    ]);

    $fulfillments = $this->fulfService->createFulfillments($sellerOrder, [
        [
            'mode' => FulfillmentMode::DROPSHIPPING->value,
            'supplier_id' => $this->platformSupplier->id,
            'supplier_location_id' => $this->location1->id,
            'items' => [
                ['order_item_id' => $orderItem->id, 'quantity' => '1.00000000'],
            ],
        ],
    ]);

    $fulfillment = $fulfillments[0];

    // Create Purchase Order via Orchestrator
    $po = $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillment);

    expect($po)->not->toBeNull()
        ->and($po->status)->toBe(PurchaseOrderStatus::SUBMITTED->value)
        ->and($po->supplier_id)->toBe($this->platformSupplier->id)
        ->and($po->order_fulfillment_id)->toBe($fulfillment->id)
        ->and($po->lines)->toHaveCount(1)
        ->and($po->total_minor)->toBe(1200);

    // Replay idempotency
    $poReplay = $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillment);
    expect($poReplay->id)->toBe($po->id);

    $poLine = $po->lines->first();

    // 1. Reconcile matching invoice
    $matchedInvoice = $this->invoiceService->recordAndReconcileInvoice(
        po: $po,
        invoiceNumber: 'INV-MATCH-001',
        lines: [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity' => '1.00000000',
                'unit_cost_minor' => 1200,
            ],
        ]
    );

    expect($matchedInvoice->reconciliation_status)->toBe(SupplierInvoiceReconciliationStatus::MATCHED->value)
        ->and($matchedInvoice->total_minor)->toBe(1200);

    // 2. Reconcile discrepancy invoice (wrong unit cost)
    $discrepancyInvoice = $this->invoiceService->recordAndReconcileInvoice(
        po: $po,
        invoiceNumber: 'INV-DISC-002',
        lines: [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity' => '1.00000000',
                'unit_cost_minor' => 1500, // Discrepancy! PO was 1200
            ],
        ]
    );

    expect($discrepancyInvoice->reconciliation_status)->toBe(SupplierInvoiceReconciliationStatus::DISCREPANCY->value)
        ->and($discrepancyInvoice->total_minor)->toBe(1500);
});

test('fails closed if platform supplier is not enabled for tenant', function (): void {
    // Disable platform supplier access
    TenantSupplierAccess::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('supplier_id', $this->platformSupplier->id)
        ->update(['is_enabled' => false]);

    $routeResult = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: null,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR'
    );

    expect($routeResult['candidate_count'])->toBe(0)
        ->and($routeResult['selected_offer'])->toBeNull();
});
