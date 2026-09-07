<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Context\DTOs\UserContext;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\Storefront\CheckoutPage;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

/**
 * Pre-Production Readiness — error-handling audit (§2): before this fix,
 * CheckoutPage::submitShipping()/placeOrder() had no try/catch around
 * CheckoutOrchestratorInterface::reserveInventory()/markReadyForOrder(),
 * so a real inventory conflict (a plain RuntimeException thrown by
 * CheckoutInventoryReservationOrchestrator when a StockItem cannot cover
 * the requested quantity) crashed the Livewire action with an uncaught
 * exception instead of showing the customer an actionable message.
 */
class CheckoutErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inventory_conflict_during_submit_shipping_surfaces_a_flash_error_not_a_crash(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $tenant = Tenant::create(['name' => 'Err Tenant', 'slug' => 'err-tenant', 'status' => 'active']);
        TaxClass::create(['tenant_id' => $tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store 1', 'slug' => 'err-s1', 'status' => 'active']);
        $market = Market::create(['tenant_id' => $tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $channel = Channel::create(['name' => 'Web', 'handle' => 'err-web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $store->id, 'channel_id' => $channel->id, 'is_active' => true]);

        $user = User::factory()->create();

        // Deliberately NO StockItem is created for this product — any
        // requested quantity produces the real "No stock items found"
        // RuntimeException from InventoryReservationService::reserve().
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'sku' => 'ERR-WIDGET-1',
            'name' => 'Error Widget',
            'slug' => 'err-widget',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);

        $pb = PriceBook::create(['tenant_id' => $tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $tenant->id, 'price_book_id' => $pb->id, 'product_id' => $product->id, 'amount_minor' => 1000, 'currency' => 'CHF', 'status' => 'active']);

        $zone = ShippingZone::create(['tenant_id' => $tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        $method = ShippingMethod::create([
            'tenant_id' => $tenant->id,
            'code' => 'FLAT',
            'name' => 'Flat Rate',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 500,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        $context = app(ContextManager::class);
        $context->setTenant(TenantContext::from($tenant->id, $tenant->name));
        $context->setStore(StoreContext::from($store->id, $store->slug));
        $context->setUser(UserContext::authenticated($user->id, $user->email));

        $cartService = app(CartServiceInterface::class);
        $orchestrator = app(CheckoutOrchestratorInterface::class);

        $cart = $cartService->getOrCreateActiveCart(new CartContext(
            tenantId: $tenant->id,
            storeId: $store->id,
            marketId: $market->id,
            channelId: $channel->id,
            currency: 'CHF',
            userId: $user->id,
        ));
        $cartService->addLine($cart, new CartLineItemData(
            productId: $product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
        ));

        $session = $orchestrator->createFromCart($cart);
        $orchestrator->setCustomerData($session, new CheckoutCustomerData('err@example.com', 'Err', 'Widget'));
        $session = $orchestrator->setAddresses($session, new CheckoutAddress('Err Widget', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));

        // Matches the exact shape CheckoutPage::submitShipping() itself
        // builds from a real shipping-rate row (method_id/method_code) —
        // this test isolates the reservation-conflict path, not shipping
        // pricing, so the rate row is fabricated rather than recomputed.
        Livewire::actingAs($user)
            ->test(CheckoutPage::class, ['resumeCheckoutSessionId' => $session->id])
            ->set('step', 'shipping')
            ->set('shippingRates', [['id' => 'flat', 'method_id' => $method->id, 'method_code' => $method->code]])
            ->set('selectedRateId', 'flat')
            ->call('submitShipping')
            ->assertOk()
            ->assertSet('step', 'shipping');
    }
}
