<?php

declare(strict_types=1);

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
use Modules\Dropshipping\Exceptions\MissingFrozenSupplierRoutingDecisionException;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierLocation;
use Modules\Dropshipping\Models\SupplierOffer;
use Modules\Dropshipping\Models\SupplierProductVariant;
use Modules\Dropshipping\Models\TenantSupplierAccess;
use Modules\Fulfillment\Contracts\FulfillmentExecutionServiceInterface;
use Modules\Fulfillment\Enums\FulfillmentMode;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerOrder;
use Modules\Pricing\Models\ExchangeRate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->tenant = Tenant::create([
        'name' => 'DS Tenant',
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

    $this->product = Product::create([
        'tenant_id' => $this->tenant->id,
        'sku' => 'PROD-DS-001',
        'name' => 'Dropship Product',
        'slug' => 'dropship-product',
        'product_type' => 'physical',
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->product->id,
        'sku' => 'SKU-DS-001',
        'combination_hash' => 'hash-ds-001',
        'status' => 'active',
        'is_active' => true,
    ]);

    // Setup platform supplier
    $this->platformSupplier = Supplier::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'scope_type' => 'platform',
        'name' => 'Global Supplier',
        'code' => 'GL_SUPP',
        'contact_email' => 'global@supplier.com',
        'provider_type' => 'generic_dropship',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    TenantSupplierAccess::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->platformSupplier->id,
        'is_enabled' => true,
    ]);

    $this->location1 = SupplierLocation::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->platformSupplier->id,
        'code' => 'EU-CENTRAL',
        'city' => 'Frankfurt',
        'postal_code' => '60311',
        'address_line1' => 'Main St 1',
        'name' => 'Frankfurt Warehouse',
        'country_code' => 'DE',
        'is_active' => true,
    ]);

    $this->spv1 = SupplierProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->platformSupplier->id,
        'product_id' => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'supplier_sku' => 'SUPP-TSHIRT-BLK',
        'canonical_wholesale_cost_minor' => 1200,
        'currency' => 'EUR',
    ]);

    $this->offer1 = SupplierOffer::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $this->platformSupplier->id,
        'supplier_product_variant_id' => $this->spv1->id,
        'supplier_location_id' => $this->location1->id,
        'location_wholesale_cost_minor' => 1200,
        'currency' => 'EUR',
        'stock_quantity' => '100.00000000',
        'lead_time_days' => 2,
        'is_available' => true,
    ]);

    $this->routingEngine = app(SupplierRoutingEngineInterface::class);
    $this->poOrchestrator = app(DropshipOrderOrchestratorInterface::class);
    $this->invoiceService = app(SupplierInvoiceReconciliationServiceInterface::class);
    $this->fulfService = app(FulfillmentExecutionServiceInterface::class);
});

test('evaluates candidates with FX conversion, lead time, and destination filtering', function (): void {
    ExchangeRate::create([
        'tenant_id' => $this->tenant->id,
        'base_currency' => 'USD',
        'target_currency' => 'EUR',
        'rate' => '0.90000000',
        'source' => 'test',
        'is_stale' => false,
        'effective_at' => now(),
    ]);

    // Add Supplier 2: USD offer with US location
    $usSupplier = Supplier::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'scope_type' => 'tenant',
        'name' => 'US Apparel',
        'code' => 'US_APP',
        'contact_email' => 'us@supplier.com',
        'provider_type' => 'generic_dropship',
        'currency' => 'USD',
        'status' => 'active',
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
        'location_wholesale_cost_minor' => 2000,
        'currency' => 'USD',
        'stock_quantity' => '50.00000000',
        'lead_time_days' => 5,
        'is_available' => true,
    ]);

    // 1. Without country filter, both DE and US candidates evaluated
    $routeAll = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: null,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR'
    );

    expect($routeAll['candidate_count'])->toBe(2)
        ->and($routeAll['selected_offer'])->not->toBeNull()
        ->and($routeAll['selected_offer']->id)->toBe($this->offer1->id)
        ->and($routeAll['normalized_cost_minor'])->toBe(1200)
        ->and($routeAll['audit_snapshot'])->toHaveKey('all_candidates');

    // 2. With deliveryCountryCode: 'DE', US candidate is excluded
    $routeDE = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: null,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR',
        deliveryCountryCode: 'DE'
    );

    expect($routeDE['candidate_count'])->toBe(1)
        ->and($routeDE['selected_offer']->id)->toBe($this->offer1->id);
});

test('enforces strict private vendor supplier routing isolation (A, B, C)', function (): void {
    $plan = VendorPlan::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Plan A',
        'code' => 'plan-a',
    ]);

    $vendorA = Vendor::create([
        'tenant_id' => $this->tenant->id,
        'vendor_plan_id' => $plan->id,
        'name' => 'Vendor A',
        'platform_slug' => 'vendor-a',
        'legal_name' => 'Vendor A Corp',
        'email' => 'vendor-a@example.com',
        'payout_currency' => 'EUR',
    ]);

    $vendorB = Vendor::create([
        'tenant_id' => $this->tenant->id,
        'vendor_plan_id' => $plan->id,
        'name' => 'Vendor B',
        'platform_slug' => 'vendor-b',
        'legal_name' => 'Vendor B Corp',
        'email' => 'vendor-b@example.com',
        'payout_currency' => 'EUR',
    ]);

    // Private supplier belonging to Vendor A
    $privateSupplierA = Supplier::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'scope_type' => 'private_vendor',
        'vendor_id' => $vendorA->id,
        'name' => 'Private Supplier A',
        'code' => 'PRIV_A',
        'contact_email' => 'priv-a@example.com',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    $locA = SupplierLocation::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $privateSupplierA->id,
        'name' => 'Private Location A',
        'code' => 'LOC-PRIV-A',
        'city' => 'Berlin',
        'postal_code' => '10115',
        'address_line1' => 'Private St 1',
        'country_code' => 'DE',
        'is_active' => true,
    ]);

    $spvA = SupplierProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $privateSupplierA->id,
        'product_id' => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'supplier_sku' => 'PRIV-A-SKU',
        'canonical_wholesale_cost_minor' => 800,
        'currency' => 'EUR',
    ]);

    SupplierOffer::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $privateSupplierA->id,
        'supplier_product_variant_id' => $spvA->id,
        'supplier_location_id' => $locA->id,
        'location_wholesale_cost_minor' => 800,
        'currency' => 'EUR',
        'stock_quantity' => '100.00000000',
        'lead_time_days' => 1,
        'is_available' => true,
    ]);

    // Test A: platform seller (vendorId = null) + Vendor A private supplier -> candidate EXCLUDED!
    $routePlatform = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: null,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR'
    );
    // Platform only sees the platform supplier (1200 EUR), NOT the private supplier A (800 EUR)
    expect($routePlatform['selected_offer']->supplier_id)->toBe($this->platformSupplier->id)
        ->and($routePlatform['selected_offer']->supplier_id)->not->toBe($privateSupplierA->id);

    // Test B: Vendor A seller + Vendor A private supplier -> candidate ELIGIBLE!
    $routeVendorA = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: $vendorA->id,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR'
    );
    expect($routeVendorA['selected_offer']->supplier_id)->toBe($privateSupplierA->id)
        ->and($routeVendorA['normalized_cost_minor'])->toBe(800);

    // Test C: Vendor B seller + Vendor A private supplier -> candidate EXCLUDED!
    $routeVendorB = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: $vendorB->id,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR'
    );
    expect($routeVendorB['selected_offer']->supplier_id)->toBe($this->platformSupplier->id)
        ->and($routeVendorB['selected_offer']->supplier_id)->not->toBe($privateSupplierA->id);
});

test('orchestrates purchase order materialization and invoice reconciliation with tax and shipping', function (): void {
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
            'routing_snapshot' => [
                'selected_offer_id' => $this->offer1->id,
                'supplier_id' => $this->platformSupplier->id,
                'supplier_location_id' => $this->location1->id,
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'supplier_product_variant_id' => $this->spv1->id,
                        'supplier_sku' => 'FROZEN-SKU-001',
                        'procurement_cost_minor' => 1200,
                        'procurement_currency' => 'EUR',
                    ],
                ],
            ],
            'items' => [
                ['order_item_id' => $orderItem->id, 'quantity' => '1.00000000'],
            ],
        ],
    ]);

    $fulfillment = $fulfillments[0];

    // Create Purchase Order via Orchestrator (consumes frozen routing snapshot)
    $po = $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillment);

    expect($po)->not->toBeNull()
        ->and($po->status)->toBe(PurchaseOrderStatus::DRAFT->value)
        ->and($po->supplier_id)->toBe($this->platformSupplier->id)
        ->and($po->order_fulfillment_id)->toBe($fulfillment->id)
        ->and($po->lines)->toHaveCount(1)
        ->and($po->lines->first()->supplier_sku)->toBe('FROZEN-SKU-001')
        ->and($po->total_minor)->toBe(1200);

    // Replay idempotency
    $poReplay = $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillment);
    expect($poReplay->id)->toBe($po->id);

    $poLine = $po->lines->first();

    // 1. Reconcile matching invoice with tax and shipping
    $matchedInvoice = $this->invoiceService->recordAndReconcileInvoice(
        po: $po,
        invoiceNumber: 'INV-MATCH-001',
        lines: [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity' => '1.00000000',
                'unit_cost_minor' => 1200,
                'tax_minor' => 228,
            ],
        ],
        shippingMinor: 100,
        taxMinor: 228
    );

    expect($matchedInvoice->subtotal_minor)->toBe(1200)
        ->and($matchedInvoice->tax_minor)->toBe(228)
        ->and($matchedInvoice->shipping_minor)->toBe(100)
        ->and($matchedInvoice->total_minor)->toBe(1528)
        ->and($matchedInvoice->lines->first()->tax_minor)->toBe(228);

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

    expect($discrepancyInvoice->metadata['reconciliation_status'])->toBe(SupplierInvoiceReconciliationStatus::DISCREPANCY->value)
        ->and($discrepancyInvoice->total_minor)->toBe(1500);
});

test('Test C: missing routing_snapshot fails closed with MissingFrozenSupplierRoutingDecisionException', function (): void {
    $unmappedProduct = Product::create([
        'tenant_id' => $this->tenant->id,
        'sku' => 'PROD-DS-UNMAPPED',
        'name' => 'Unmapped Product',
        'slug' => 'unmapped-product',
        'product_type' => 'physical',
        'is_active' => true,
    ]);

    $unmappedVariant = ProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $unmappedProduct->id,
        'sku' => 'SKU-UNMAPPED',
        'combination_hash' => 'hash-unmapped',
        'status' => 'active',
        'is_active' => true,
    ]);

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
        'order_number' => 'ORD-DS-UNMAPPED',
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
        'product_id' => $unmappedProduct->id,
        'variant_id' => $unmappedVariant->id,
        'sku_snapshot' => 'CUSTOMER-RETAIL-SKU',
        'name_snapshot' => 'Unmapped Item',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => true,
        'quantity' => '1.00000000',
        'unit_price_minor' => 9999, // Retail price must NEVER be used!
        'subtotal_minor' => 9999,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 9999,
    ]);

    $sellerOrder = SellerOrder::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'order_id' => $order->id,
        'seller_order_number' => 'ORD-DS-UNM-PLT',
        'seller_type' => 'platform',
        'vendor_id' => null,
        'commercial_model' => 'platform_as_merchant_of_record',
        'currency' => 'EUR',
        'subtotal_minor' => 9999,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'shipping_original_minor' => 0,
        'shipping_discount_minor' => 0,
        'shipping_final_minor' => 0,
        'total_minor' => 9999,
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

    // Attempting PO creation must FAIL CLOSED with typed exception!
    // No routing_snapshot was provided: automatic procurement must never fall back
    // to a live/mutable SupplierProductVariant + SupplierOffer lookup.
    $this->expectException(MissingFrozenSupplierRoutingDecisionException::class);
    $this->expectExceptionMessage('routing_snapshot is missing or has no frozen [items] decisions');

    $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillments[0]);
});

test('calculates fractional procurement decimal money math accurately without float truncation', function (): void {
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
        'order_number' => 'ORD-DS-FRAC',
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
        'merchandise_subtotal_minor' => 1000,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'grand_total_minor' => 1000,
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
        'quantity' => '0.33333333',
        'unit_price_minor' => 3000,
        'subtotal_minor' => 1000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 1000,
    ]);

    $sellerOrder = SellerOrder::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'order_id' => $order->id,
        'seller_order_number' => 'ORD-DS-FRAC-PLT',
        'seller_type' => 'platform',
        'vendor_id' => null,
        'commercial_model' => 'platform_as_merchant_of_record',
        'currency' => 'EUR',
        'subtotal_minor' => 1000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'shipping_original_minor' => 0,
        'shipping_discount_minor' => 0,
        'shipping_final_minor' => 0,
        'total_minor' => 1000,
        'commission_total_minor' => 0,
        'status' => 'open',
    ]);

    $fulfillments = $this->fulfService->createFulfillments($sellerOrder, [
        [
            'mode' => FulfillmentMode::DROPSHIPPING->value,
            'supplier_id' => $this->platformSupplier->id,
            'supplier_location_id' => $this->location1->id,
            'routing_snapshot' => [
                'supplier_id' => $this->platformSupplier->id,
                'supplier_location_id' => $this->location1->id,
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'supplier_product_variant_id' => $this->spv1->id,
                        'supplier_sku' => 'FROZEN-SKU-FRAC',
                        'procurement_cost_minor' => 1200,
                        'procurement_currency' => 'EUR',
                    ],
                ],
            ],
            'items' => [
                ['order_item_id' => $orderItem->id, 'quantity' => '0.33333333'],
            ],
        ],
    ]);

    $po = $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillments[0]);

    // 0.33333333 * 1200 minor = 399.999996 -> rounds to 400 minor with HalfUp!
    expect($po->lines->first()->total_cost_minor)->toBe(400)
        ->and($po->total_minor)->toBe(400);
});

test('Test A: PO still uses the frozen original cost after the routed Offer price later changes', function (): void {
    $cart = Cart::create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'market_id' => $this->market->id,
        'channel_id' => $this->channel->id, 'currency' => 'EUR', 'locale' => 'de', 'status' => 'active',
    ]);
    $session = CheckoutSession::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'cart_id' => $cart->id,
        'store_id' => $this->store->id, 'market_id' => $this->market->id, 'channel_id' => $this->channel->id,
        'currency' => 'EUR', 'locale' => 'de', 'state' => 'ready_for_order',
    ]);
    $order = Order::create([
        'order_number' => 'ORD-DS-FROZEN-A', 'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'market_id' => $this->market->id, 'channel_id' => $this->channel->id, 'checkout_id' => $session->id,
        'currency' => 'EUR', 'locale' => 'de', 'order_status' => 'placed', 'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled', 'merchandise_subtotal_minor' => 3000, 'discount_total_minor' => 0,
        'tax_total_minor' => 0, 'shipping_total_minor' => 0, 'grand_total_minor' => 3000,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record', 'customer_snapshot' => ['email' => 'a@example.com'],
        'version' => 1, 'placed_at' => now(),
    ]);
    $orderItem = OrderItem::create([
        'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $this->product->id,
        'variant_id' => $this->variant->id, 'sku_snapshot' => $this->variant->sku, 'name_snapshot' => $this->product->name,
        'product_type_snapshot' => 'physical', 'requires_shipping_snapshot' => true, 'quantity' => '1.00000000',
        'unit_price_minor' => 3000, 'subtotal_minor' => 3000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 3000,
    ]);
    $sellerOrder = SellerOrder::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'order_id' => $order->id, 'seller_order_number' => 'ORD-DS-FROZEN-A-PLT', 'seller_type' => 'platform',
        'vendor_id' => null, 'commercial_model' => 'platform_as_merchant_of_record', 'currency' => 'EUR',
        'subtotal_minor' => 3000, 'discount_minor' => 0, 'tax_minor' => 0, 'shipping_original_minor' => 0,
        'shipping_discount_minor' => 0, 'shipping_final_minor' => 0, 'total_minor' => 3000, 'commission_total_minor' => 0,
        'status' => 'open',
    ]);

    // SupplierRoutingEngine selects Offer A (cost 1200 at the time of routing).
    $routeResult = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: null,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR',
    );
    expect($routeResult['selected_offer']->id)->toBe($this->offer1->id);

    $fulfillments = $this->fulfService->createFulfillments($sellerOrder, [
        [
            'mode' => FulfillmentMode::DROPSHIPPING->value,
            'supplier_id' => $this->platformSupplier->id,
            'supplier_location_id' => $this->location1->id,
            'routing_snapshot' => [
                'supplier_id' => $this->platformSupplier->id,
                'supplier_location_id' => $this->location1->id,
                'selected_offer_id' => $this->offer1->id,
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'supplier_product_variant_id' => $this->spv1->id,
                        'supplier_sku' => $this->spv1->supplier_sku,
                        'procurement_cost_minor' => $routeResult['normalized_cost_minor'],
                        'procurement_currency' => 'EUR',
                    ],
                ],
            ],
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => '1.00000000']],
        ],
    ]);

    // Offer A's price changes AFTER the routing decision was frozen.
    $this->offer1->update(['location_wholesale_cost_minor' => 9999]);

    $po = $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillments[0]);

    expect($po->lines->first()->unit_cost_minor)->toBe(1200)
        ->and($po->total_minor)->toBe(1200)
        ->and($po->total_minor)->not->toBe(9999);
});

test('Test B: PO still uses the frozen Offer A cost even though a cheaper Offer B exists before PO creation', function (): void {
    $cart = Cart::create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'market_id' => $this->market->id,
        'channel_id' => $this->channel->id, 'currency' => 'EUR', 'locale' => 'de', 'status' => 'active',
    ]);
    $session = CheckoutSession::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'cart_id' => $cart->id,
        'store_id' => $this->store->id, 'market_id' => $this->market->id, 'channel_id' => $this->channel->id,
        'currency' => 'EUR', 'locale' => 'de', 'state' => 'ready_for_order',
    ]);
    $order = Order::create([
        'order_number' => 'ORD-DS-FROZEN-B', 'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'market_id' => $this->market->id, 'channel_id' => $this->channel->id, 'checkout_id' => $session->id,
        'currency' => 'EUR', 'locale' => 'de', 'order_status' => 'placed', 'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled', 'merchandise_subtotal_minor' => 3000, 'discount_total_minor' => 0,
        'tax_total_minor' => 0, 'shipping_total_minor' => 0, 'grand_total_minor' => 3000,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record', 'customer_snapshot' => ['email' => 'b@example.com'],
        'version' => 1, 'placed_at' => now(),
    ]);
    $orderItem = OrderItem::create([
        'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $this->product->id,
        'variant_id' => $this->variant->id, 'sku_snapshot' => $this->variant->sku, 'name_snapshot' => $this->product->name,
        'product_type_snapshot' => 'physical', 'requires_shipping_snapshot' => true, 'quantity' => '1.00000000',
        'unit_price_minor' => 3000, 'subtotal_minor' => 3000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 3000,
    ]);
    $sellerOrder = SellerOrder::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'order_id' => $order->id, 'seller_order_number' => 'ORD-DS-FROZEN-B-PLT', 'seller_type' => 'platform',
        'vendor_id' => null, 'commercial_model' => 'platform_as_merchant_of_record', 'currency' => 'EUR',
        'subtotal_minor' => 3000, 'discount_minor' => 0, 'tax_minor' => 0, 'shipping_original_minor' => 0,
        'shipping_discount_minor' => 0, 'shipping_final_minor' => 0, 'total_minor' => 3000, 'commission_total_minor' => 0,
        'status' => 'open',
    ]);

    // Route selects Offer A (cost 1200) at the same Supplier / Location.
    $routeResult = $this->routingEngine->routeVariant(
        tenantId: $this->tenant->id,
        vendorId: null,
        variant: $this->variant,
        quantity: '1.00000000',
        targetCurrency: 'EUR',
    );
    expect($routeResult['selected_offer']->id)->toBe($this->offer1->id);

    $fulfillments = $this->fulfService->createFulfillments($sellerOrder, [
        [
            'mode' => FulfillmentMode::DROPSHIPPING->value,
            'supplier_id' => $this->platformSupplier->id,
            'supplier_location_id' => $this->location1->id,
            'routing_snapshot' => [
                'supplier_id' => $this->platformSupplier->id,
                'supplier_location_id' => $this->location1->id,
                'selected_offer_id' => $this->offer1->id,
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'supplier_product_variant_id' => $this->spv1->id,
                        'supplier_sku' => $this->spv1->supplier_sku,
                        'procurement_cost_minor' => $routeResult['normalized_cost_minor'],
                        'procurement_currency' => 'EUR',
                    ],
                ],
            ],
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => '1.00000000']],
        ],
    ]);

    // A second, cheaper Offer B for the SAME variant becomes available before PO creation.
    // If PurchaseOrder creation ever re-ran routing, it would prefer this one.
    $location2 = SupplierLocation::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'supplier_id' => $this->platformSupplier->id,
        'code' => 'EU-WEST', 'city' => 'Amsterdam', 'postal_code' => '1000AA', 'address_line1' => 'Dam 1',
        'name' => 'Amsterdam Warehouse', 'country_code' => 'NL', 'is_active' => true,
    ]);
    SupplierOffer::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'supplier_id' => $this->platformSupplier->id,
        'supplier_product_variant_id' => $this->spv1->id, 'supplier_location_id' => $location2->id,
        'location_wholesale_cost_minor' => 100, 'currency' => 'EUR', 'stock_quantity' => '100.00000000',
        'lead_time_days' => 1, 'is_available' => true,
    ]);

    $po = $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillments[0]);

    expect($po->lines->first()->unit_cost_minor)->toBe(1200)
        ->and($po->total_minor)->toBe(1200)
        ->and($po->total_minor)->not->toBe(100);
});

test('Test D: PO cannot silently substitute a different Supplier/Location than the frozen routing decision', function (): void {
    $otherSupplier = Supplier::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'scope_type' => 'tenant',
        'name' => 'Other Supplier', 'code' => 'OTHER_SUPP', 'contact_email' => 'other@supplier.com',
        'currency' => 'EUR', 'status' => 'active',
    ]);
    $otherLocation = SupplierLocation::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'supplier_id' => $otherSupplier->id,
        'code' => 'OTHER-LOC', 'city' => 'Paris', 'postal_code' => '75001', 'address_line1' => 'Rue 1',
        'name' => 'Paris Warehouse', 'country_code' => 'FR', 'is_active' => true,
    ]);

    $cart = Cart::create([
        'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'market_id' => $this->market->id,
        'channel_id' => $this->channel->id, 'currency' => 'EUR', 'locale' => 'de', 'status' => 'active',
    ]);
    $session = CheckoutSession::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'cart_id' => $cart->id,
        'store_id' => $this->store->id, 'market_id' => $this->market->id, 'channel_id' => $this->channel->id,
        'currency' => 'EUR', 'locale' => 'de', 'state' => 'ready_for_order',
    ]);
    $order = Order::create([
        'order_number' => 'ORD-DS-FROZEN-D', 'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'market_id' => $this->market->id, 'channel_id' => $this->channel->id, 'checkout_id' => $session->id,
        'currency' => 'EUR', 'locale' => 'de', 'order_status' => 'placed', 'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled', 'merchandise_subtotal_minor' => 3000, 'discount_total_minor' => 0,
        'tax_total_minor' => 0, 'shipping_total_minor' => 0, 'grand_total_minor' => 3000,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record', 'customer_snapshot' => ['email' => 'd@example.com'],
        'version' => 1, 'placed_at' => now(),
    ]);
    $orderItem = OrderItem::create([
        'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $this->product->id,
        'variant_id' => $this->variant->id, 'sku_snapshot' => $this->variant->sku, 'name_snapshot' => $this->product->name,
        'product_type_snapshot' => 'physical', 'requires_shipping_snapshot' => true, 'quantity' => '1.00000000',
        'unit_price_minor' => 3000, 'subtotal_minor' => 3000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 3000,
    ]);
    $sellerOrder = SellerOrder::create([
        'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'order_id' => $order->id, 'seller_order_number' => 'ORD-DS-FROZEN-D-PLT', 'seller_type' => 'platform',
        'vendor_id' => null, 'commercial_model' => 'platform_as_merchant_of_record', 'currency' => 'EUR',
        'subtotal_minor' => 3000, 'discount_minor' => 0, 'tax_minor' => 0, 'shipping_original_minor' => 0,
        'shipping_discount_minor' => 0, 'shipping_final_minor' => 0, 'total_minor' => 3000, 'commission_total_minor' => 0,
        'status' => 'open',
    ]);

    // Routed and frozen against Supplier A / Location A (platformSupplier / location1).
    $fulfillments = $this->fulfService->createFulfillments($sellerOrder, [
        [
            'mode' => FulfillmentMode::DROPSHIPPING->value,
            'supplier_id' => $this->platformSupplier->id,
            'supplier_location_id' => $this->location1->id,
            'routing_snapshot' => [
                'supplier_id' => $this->platformSupplier->id,
                'supplier_location_id' => $this->location1->id,
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'supplier_product_variant_id' => $this->spv1->id,
                        'supplier_sku' => $this->spv1->supplier_sku,
                        'procurement_cost_minor' => 1200,
                        'procurement_currency' => 'EUR',
                    ],
                ],
            ],
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => '1.00000000']],
        ],
    ]);
    $fulfillment = $fulfillments[0];

    // Simulate an attempted substitution: the Fulfillment row now points at Supplier B /
    // Location B, while the frozen routing_snapshot still records Supplier A / Location A.
    DB::table('order_fulfillments')
        ->where('id', $fulfillment->id)
        ->update(['supplier_id' => $otherSupplier->id, 'supplier_location_id' => $otherLocation->id]);

    $this->expectException(MissingFrozenSupplierRoutingDecisionException::class);
    $this->expectExceptionMessage('frozen routing_snapshot supplier_id does not match Fulfillment Supplier');

    $this->poOrchestrator->createPurchaseOrderForFulfillment($fulfillment->fresh());
});
