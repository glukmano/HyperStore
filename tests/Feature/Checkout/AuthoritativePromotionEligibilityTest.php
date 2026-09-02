<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\Services\CheckoutPricingOrchestrator;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\Contracts\TaxCalculatorInterface;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Pricing\Models\TaxRate;
use Modules\Pricing\Models\TaxZone;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionResult;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Services\PromotionRuleEngine;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['name' => 'Promo Eligibility Tenant', 'slug' => 'promo-tenant', 'status' => 'active']);
    $this->market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Europe Market',
        'code' => 'EUR-MKT',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
    ]);
    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'EU Webstore',
        'slug' => 'eu-web',
        'code' => 'EU-WEB',
        'default_locale' => 'en',
        'default_currency' => 'EUR',
        'status' => 'active',
    ]);
    $this->channel = Channel::create([
        'name' => 'Web Channel',
        'handle' => 'web-'.uniqid(),
        'type' => 'website',
        'is_active' => true,
    ]);
    StoreChannel::create([
        'store_id' => $this->store->id,
        'channel_id' => $this->channel->id,
        'is_active' => true,
    ]);

    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $this->user = User::factory()->create();

    // Default 10% tax zone and rate
    $this->taxZone = TaxZone::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'DE_ELIG_ZONE',
        'name' => 'Germany Tax Zone',
        'country_code' => 'DE',
        'priority' => 10,
    ]);
    $this->taxClass = TaxClass::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'STD_TAX',
        'name' => 'Standard Tax Rate',
        'is_default' => true,
    ]);
    TaxRate::create([
        'tenant_id' => $this->tenant->id,
        'tax_class_id' => $this->taxClass->id,
        'tax_zone_id' => $this->taxZone->id,
        'name' => '10% VAT',
        'rate_percentage' => '10.0000',
        'priority' => 0,
    ]);

    // Free shipping setup
    $sz = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'DE_SZ', 'name' => 'DE Shipping Zone', 'status' => 'active']);
    ShippingZoneRule::create(['shipping_zone_id' => $sz->id, 'rule_type' => 'country', 'country_code' => 'DE']);
    $this->shippingMethod = ShippingMethod::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'FREE_SHIP',
        'name' => 'Free Shipping',
        'rate_calculator_type' => 'flat_rate',
        'currency' => 'EUR',
        'base_amount' => 0,
        'status' => 'active',
    ]);
    ShippingMethodZone::create(['shipping_method_id' => $this->shippingMethod->id, 'shipping_zone_id' => $sz->id]);

    $this->cartService = app(CartServiceInterface::class);
    $this->checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $this->orderCreation = app(OrderCreationServiceInterface::class);
});

function createTestProduct(
    object $test,
    int $priceMinor,
    string $sku,
    string $productType = 'physical',
    ?TaxClass $taxClass = null
): Product {
    $tc = $taxClass ?? $test->taxClass;
    $product = Product::create([
        'tenant_id' => $test->tenant->id,
        'sku' => $sku,
        'name' => "Product {$sku}",
        'slug' => strtolower($sku),
        'product_type' => $productType,
        'status' => 'active',
        'tax_class_id' => $tc->id,
        'metadata' => ['tax_class_id' => $tc->id],
    ]);

    $wh = Warehouse::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'code' => 'WH-ELIG'],
        ['name' => 'WH Elig', 'country_code' => 'DE']
    );
    $src = InventorySource::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-ELIG'],
        ['name' => 'Source Elig', 'priority' => 10]
    );
    StockItem::create([
        'tenant_id' => $test->tenant->id,
        'inventory_source_id' => $src->id,
        'product_id' => $product->id,
        'on_hand' => 100,
        'reserved' => 0,
    ]);

    $pb = PriceBook::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'code' => 'EUR_PB'],
        ['name' => 'EUR PB', 'currency' => 'EUR', 'status' => 'active', 'priority' => 1]
    );
    Price::create([
        'tenant_id' => $test->tenant->id,
        'price_book_id' => $pb->id,
        'product_id' => $product->id,
        'amount_minor' => $priceMinor,
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    return $product;
}

test('TEST A: global percentage promotion returns all cart lines as eligible', function (): void {
    $p1 = createTestProduct($this, 5000, 'SKU-A1');
    $p2 = createTestProduct($this, 3000, 'SKU-A2');

    // Global 10% coupon promo
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '10% Off Everything',
        'code' => 'GLOBAL10',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 10],
    ]);
    Coupon::create(['tenant_id' => $this->tenant->id, 'promotion_id' => $promo->id, 'code' => 'SAVE10', 'status' => 'active']);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $line1 = $this->cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $line2 = $this->cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('a@example.com', 'A', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('A Customer', ['Street 1'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->applyCoupon($session, 'SAVE10');
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    // Total subtotal: 8000. 10% discount: 800.
    // Line 1: 5000 -> 500 discount. Line 2: 3000 -> 300 discount.
    $pLines = $ready->pricingSnapshot['lines'];
    expect($pLines[0]['allocated_cart_discount_minor'])->toBe(500)
        ->and($pLines[1]['allocated_cart_discount_minor'])->toBe(300);

    $orderResult = $this->orderCreation->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    expect($orderResult->order->discount_total_minor)->toBe(800);
});

test('TEST B: product-specific promotion allocates only to the eligible product line', function (): void {
    $p1 = createTestProduct($this, 5000, 'SKU-B1');
    $p2 = createTestProduct($this, 5000, 'SKU-B2');

    // Promotion restricted only to SKU-B1
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '20% Off B1 Only',
        'code' => 'PROMO_B1',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'product',
        'parameters' => ['product_ids' => [$p1->id]],
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 20],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $line1 = $this->cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $line2 = $this->cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('b@example.com', 'B', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('B Customer', ['Street 2'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    // 20% off line 1 (5000 minor) = 1000 minor discount.
    // Line 1 gets 1000 discount. Line 2 gets 0 discount.
    $pLines = $ready->pricingSnapshot['lines'];
    $item1 = collect($pLines)->firstWhere('product_id', $p1->id);
    $item2 = collect($pLines)->firstWhere('product_id', $p2->id);

    expect($item1['allocated_cart_discount_minor'])->toBe(1000)
        ->and($item1['taxable_amount_minor'])->toBe(4000)
        ->and($item1['tax_minor'])->toBe(400) // 10% of 4000
        ->and($item2['allocated_cart_discount_minor'])->toBe(0)
        ->and($item2['taxable_amount_minor'])->toBe(5000)
        ->and($item2['tax_minor'])->toBe(500); // 10% of 5000
});

test('TEST C: category-specific promotion allocates only to category lines', function (): void {
    $cat = Category::create(['tenant_id' => $this->tenant->id, 'code' => 'ELECTRONICS', 'status' => 'active', 'sort_order' => 1]);

    $p1 = createTestProduct($this, 6000, 'SKU-C1');
    $p2 = createTestProduct($this, 4000, 'SKU-C2');

    // Attach p1 to category
    $p1->categories()->attach($cat->id, ['is_primary' => true]);

    // Category promo: 1500 minor fixed off
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '15 EUR Off Electronics',
        'code' => 'PROMO_CAT_ELEC',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'category',
        'parameters' => ['category_ids' => [$cat->id]],
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 1500, 'currency' => 'EUR'],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $line1 = $this->cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $line2 = $this->cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('c@example.com', 'C', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('C Customer', ['Street 3'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    $pLines = $ready->pricingSnapshot['lines'];
    $item1 = collect($pLines)->firstWhere('product_id', $p1->id);
    $item2 = collect($pLines)->firstWhere('product_id', $p2->id);

    expect($item1['allocated_cart_discount_minor'])->toBe(1500)
        ->and($item2['allocated_cart_discount_minor'])->toBe(0);
});

test('TEST D: product-type-specific promotion allocates only to matching product-type lines', function (): void {
    $pPhys = createTestProduct($this, 4000, 'SKU-D-PHYS', productType: 'physical');
    $pDig = createTestProduct($this, 4000, 'SKU-D-DIG', productType: 'digital');

    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '10% Off Physical Only',
        'code' => 'PROMO_PHYS10',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'product_type',
        'parameters' => ['product_types' => ['physical']],
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 10],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $this->cartService->addLine($cart, new CartLineItemData($pPhys->id, null, CartQuantity::fromInt(1)));
    $this->cartService->addLine($cart, new CartLineItemData($pDig->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('d@example.com', 'D', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('D Customer', ['Street 4'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    $pLines = $ready->pricingSnapshot['lines'];
    $itemPhys = collect($pLines)->firstWhere('product_id', $pPhys->id);
    $itemDig = collect($pLines)->firstWhere('product_id', $pDig->id);

    // 10% of physical item (4000) = 400 minor discount
    expect($itemPhys['allocated_cart_discount_minor'])->toBe(400)
        ->and($itemDig['allocated_cart_discount_minor'])->toBe(0);
});

test('TEST E: same product on two cart lines with different options preserves cart_line_id identity', function (): void {
    $p = createTestProduct($this, 5000, 'SKU-E-CUSTOM');

    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '1000 Minor Off Product E',
        'code' => 'PROMO_E1000',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'product',
        'parameters' => ['product_ids' => [$p->id]],
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 1000, 'currency' => 'EUR'],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $line1 = $this->cartService->addLine($cart, new CartLineItemData($p->id, null, CartQuantity::fromInt(1), options: ['color' => 'red']));
    $line2 = $this->cartService->addLine($cart, new CartLineItemData($p->id, null, CartQuantity::fromInt(1), options: ['color' => 'blue']));

    expect($line1->id)->not->toBe($line2->id);

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('e@example.com', 'E', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('E Customer', ['Street 5'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    $pLines = $ready->pricingSnapshot['lines'];
    $item1 = collect($pLines)->firstWhere('cart_line_id', $line1->id);
    $item2 = collect($pLines)->firstWhere('cart_line_id', $line2->id);

    // Both lines receive 500 minor share of the 1000 minor discount
    expect($item1['allocated_cart_discount_minor'])->toBe(500)
        ->and($item2['allocated_cart_discount_minor'])->toBe(500);
});

test('TEST F: Buy-X-Get-Y restricts allocation to the authoritative affected line scope', function (): void {
    $pTarget = createTestProduct($this, 2000, 'SKU-F-TARGET');
    $pOther = createTestProduct($this, 3000, 'SKU-F-OTHER');

    // Buy 2 Get 1 Free on pTarget
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Buy 2 Get 1 Free',
        'code' => 'B2G1_PROMO',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'buy_x_get_y',
        'parameters' => [
            'buy_quantity' => 2,
            'get_free_quantity' => 1,
            'product_id' => $pTarget->id,
        ],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $lineTarget = $this->cartService->addLine($cart, new CartLineItemData($pTarget->id, null, CartQuantity::fromInt(3)));
    $lineOther = $this->cartService->addLine($cart, new CartLineItemData($pOther->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('f@example.com', 'F', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('F Customer', ['Street 6'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    $pLines = $ready->pricingSnapshot['lines'];
    $itemTarget = collect($pLines)->firstWhere('product_id', $pTarget->id);
    $itemOther = collect($pLines)->firstWhere('product_id', $pOther->id);

    // Free item value = 2000 minor. Target line subtotal is 6000 minor.
    // Target line receives 2000 minor discount. Other line receives 0!
    expect($itemTarget['allocated_cart_discount_minor'])->toBe(2000)
        ->and($itemOther['allocated_cart_discount_minor'])->toBe(0);
});

test('TEST G: Fixed-price action gives zero allocation to unrelated lines', function (): void {
    $pTarget = createTestProduct($this, 5000, 'SKU-G-TARGET');
    $pOther = createTestProduct($this, 3000, 'SKU-G-OTHER');

    // Target promo price: 3500 minor (1500 minor off)
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Fixed Price 35 EUR Promo',
        'code' => 'FIXED_PRICE_35',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'fixed_price',
        'parameters' => [
            'amount_minor' => 3500,
            'product_id' => $pTarget->id,
        ],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $this->cartService->addLine($cart, new CartLineItemData($pTarget->id, null, CartQuantity::fromInt(1)));
    $this->cartService->addLine($cart, new CartLineItemData($pOther->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('g@example.com', 'G', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('G Customer', ['Street 7'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    $pLines = $ready->pricingSnapshot['lines'];
    $itemTarget = collect($pLines)->firstWhere('product_id', $pTarget->id);
    $itemOther = collect($pLines)->firstWhere('product_id', $pOther->id);

    expect($itemTarget['allocated_cart_discount_minor'])->toBe(1500)
        ->and($itemOther['allocated_cart_discount_minor'])->toBe(0);
});

test('TEST H: multiple promotions with different eligible line sets allocate over own scope only', function (): void {
    $pA = createTestProduct($this, 6000, 'SKU-H-A');
    $pB = createTestProduct($this, 4000, 'SKU-H-B');

    // Promo 1: 1000 off A
    $promo1 = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '10 Off A',
        'code' => 'PROMO_H1',
        'priority' => 2,
        'is_stackable' => true,
        'status' => 'active',
    ]);
    PromotionCondition::create([
        'promotion_id' => $promo1->id,
        'condition_type' => 'product',
        'parameters' => ['product_ids' => [$pA->id]],
    ]);
    PromotionAction::create([
        'promotion_id' => $promo1->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 1000, 'currency' => 'EUR'],
    ]);

    // Promo 2: 500 off B
    $promo2 = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '5 Off B',
        'code' => 'PROMO_H2',
        'priority' => 1,
        'is_stackable' => true,
        'status' => 'active',
    ]);
    PromotionCondition::create([
        'promotion_id' => $promo2->id,
        'condition_type' => 'product',
        'parameters' => ['product_ids' => [$pB->id]],
    ]);
    PromotionAction::create([
        'promotion_id' => $promo2->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 500, 'currency' => 'EUR'],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $this->cartService->addLine($cart, new CartLineItemData($pA->id, null, CartQuantity::fromInt(1)));
    $this->cartService->addLine($cart, new CartLineItemData($pB->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('h@example.com', 'H', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('H Customer', ['Street 8'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    $pLines = $ready->pricingSnapshot['lines'];
    $itemA = collect($pLines)->firstWhere('product_id', $pA->id);
    $itemB = collect($pLines)->firstWhere('product_id', $pB->id);

    expect($itemA['allocated_cart_discount_minor'])->toBe(1000)
        ->and($itemB['allocated_cart_discount_minor'])->toBe(500);
});

test('TEST I: unknown, empty, or duplicate eligible cart line ID from Promotions fails closed', function (): void {
    $p = createTestProduct($this, 5000, 'SKU-I');

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $line = $this->cartService->addLine($cart, new CartLineItemData($p->id, null, CartQuantity::fromInt(1)));

    $pricingOrch = app(CheckoutPricingOrchestrator::class);

    // Case 1: Empty eligibleCartLineIds
    $mockRuleEngine = mock(PromotionRuleEngine::class);
    $mockRuleEngine->shouldReceive('evaluate')->andReturn(new PromotionResult(
        subtotal: MoneyValue::fromMinor(5000, 'EUR'),
        totalDiscount: MoneyValue::fromMinor(1000, 'EUR'),
        finalTotal: MoneyValue::fromMinor(4000, 'EUR'),
        discounts: [
            new DiscountLine(
                promotionId: 1,
                promotionCode: 'BAD_PROMO',
                description: 'Bad Promo Empty',
                discountAmount: MoneyValue::fromMinor(1000, 'EUR'),
                eligibleCartLineIds: []
            ),
        ]
    ));

    $badOrch = new CheckoutPricingOrchestrator(
        priceResolver: app(PriceResolverInterface::class),
        promotionRuleEngine: $mockRuleEngine,
        taxCalculator: app(TaxCalculatorInterface::class)
    );

    expect(fn () => $badOrch->calculate($cart))
        ->toThrow(\RuntimeException::class, 'returned empty eligibleCartLineIds');

    // Case 2: Unknown cart line ID
    $mockRuleEngine2 = mock(PromotionRuleEngine::class);
    $mockRuleEngine2->shouldReceive('evaluate')->andReturn(new PromotionResult(
        subtotal: MoneyValue::fromMinor(5000, 'EUR'),
        totalDiscount: MoneyValue::fromMinor(1000, 'EUR'),
        finalTotal: MoneyValue::fromMinor(4000, 'EUR'),
        discounts: [
            new DiscountLine(
                promotionId: 1,
                promotionCode: 'UNKNOWN_LINE',
                description: 'Unknown Line',
                discountAmount: MoneyValue::fromMinor(1000, 'EUR'),
                eligibleCartLineIds: [999999]
            ),
        ]
    ));

    $badOrch2 = new CheckoutPricingOrchestrator(
        priceResolver: app(PriceResolverInterface::class),
        promotionRuleEngine: $mockRuleEngine2,
        taxCalculator: app(TaxCalculatorInterface::class)
    );

    expect(fn () => $badOrch2->calculate($cart))
        ->toThrow(\RuntimeException::class, 'references unknown cart_line_id');

    // Case 3: Duplicate cart line ID
    $mockRuleEngine3 = mock(PromotionRuleEngine::class);
    $mockRuleEngine3->shouldReceive('evaluate')->andReturn(new PromotionResult(
        subtotal: MoneyValue::fromMinor(5000, 'EUR'),
        totalDiscount: MoneyValue::fromMinor(1000, 'EUR'),
        finalTotal: MoneyValue::fromMinor(4000, 'EUR'),
        discounts: [
            new DiscountLine(
                promotionId: 1,
                promotionCode: 'DUP_LINE',
                description: 'Duplicate Line',
                discountAmount: MoneyValue::fromMinor(1000, 'EUR'),
                eligibleCartLineIds: [$line->id, $line->id]
            ),
        ]
    ));

    $badOrch3 = new CheckoutPricingOrchestrator(
        priceResolver: app(PriceResolverInterface::class),
        promotionRuleEngine: $mockRuleEngine3,
        taxCalculator: app(TaxCalculatorInterface::class)
    );

    expect(fn () => $badOrch3->calculate($cart))
        ->toThrow(\RuntimeException::class, 'returned duplicate eligibleCartLineIds');
});

test('TEST J: no fallback-to-all-lines exists when promotion condition does not match any lines', function (): void {
    $p = createTestProduct($this, 5000, 'SKU-J');

    // Promotion targeting an unmatching product ID 99999
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Unmatching Promo',
        'code' => 'PROMO_UNMATCH',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'product',
        'parameters' => ['product_ids' => [99999]],
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 20],
    ]);

    $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $this->cartService->addLine($cart, new CartLineItemData($p->id, null, CartQuantity::fromInt(1)));

    $session = $this->checkoutOrch->createFromCart($cart);
    $session = $this->checkoutOrch->setCustomerData($session, new CheckoutCustomerData('j@example.com', 'J', 'Customer'));
    $session = $this->checkoutOrch->setAddresses($session, new CheckoutAddress('J Customer', ['Street 10'], 'Berlin', 'DE', postalCode: '10115'));
    $session = $this->checkoutOrch->selectShippingQuote($session, ['method_id' => $this->shippingMethod->id, 'method_code' => $this->shippingMethod->code]);
    $session = $this->checkoutOrch->reserveInventory($session);
    $ready = $this->checkoutOrch->markReadyForOrder($session);

    // Promotion must NOT apply, and must NOT fall back to discounting all lines
    expect($ready->totals['cart_discounts'])->toBe(0)
        ->and($ready->pricingSnapshot['lines'][0]['allocated_cart_discount_minor'])->toBe(0);
});
