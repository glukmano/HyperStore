<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Tests\TestCase;

class CartLifecycleAndMergeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user;

    private Product $physicalProduct;

    private Product $digitalProduct;

    private CartServiceInterface $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Cart Tenant', 'slug' => 'cart-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'S1', 'name' => 'Store 1', 'slug' => 's1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        $this->user = User::factory()->create();

        $this->physicalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'T-Shirt',
            'slug' => 't-shirt',
            'sku' => 'TSHIRT-1',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 0.5,
        ]);

        $this->digitalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'E-Book',
            'slug' => 'e-book',
            'sku' => 'EBOOK-1',
            'product_type' => 'digital',
            'status' => 'active',
        ]);

        $this->cartService = app(CartServiceInterface::class);
    }

    public function test_guest_cart_creation_hashes_token_at_rest_and_never_stores_plaintext(): void
    {
        $rawToken = bin2hex(random_bytes(32)); // 64-char hex
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            guestToken: $rawToken
        );

        $cart = $this->cartService->getOrCreateActiveCart($ctx);

        $this->assertNotNull($cart->guest_token_hash);
        $this->assertSame(hash('sha256', $rawToken), $cart->guest_token_hash);
        $this->assertDatabaseMissing('carts', ['guest_token_hash' => $rawToken]);
        $this->assertDatabaseHas('carts', ['guest_token_hash' => hash('sha256', $rawToken)]);
    }

    public function test_authenticated_customer_cart_uniqueness(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );

        $cart1 = $this->cartService->getOrCreateActiveCart($ctx);
        $cart2 = $this->cartService->getOrCreateActiveCart($ctx);

        $this->assertSame($cart1->id, $cart2->id);
    }

    public function test_store_channel_eligibility_enforced_on_cart_creation(): void
    {
        $disabledChannel = Channel::create(['name' => 'POS', 'handle' => 'pos', 'is_active' => true]);

        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $disabledChannel->id,
            currency: 'CHF',
            userId: $this->user->id
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Channel [{$disabledChannel->id}] is not enabled for Store [{$this->store->id}].");

        $this->cartService->getOrCreateActiveCart($ctx);
    }

    public function test_adding_identical_items_merges_quantities_via_signature(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);

        $item1 = new CartLineItemData(
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(2),
            options: ['color' => 'blue', 'size' => 'L']
        );

        $item2 = new CartLineItemData(
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(3),
            options: ['size' => 'L', 'color' => 'blue'] // different order, same signature
        );

        $line1 = $this->cartService->addLine($cart, $item1);
        $line2 = $this->cartService->addLine($cart, $item2);

        $this->assertSame($line1->id, $line2->id);
        $this->assertSame('5', (string) $line2->fresh()->quantity);
        $this->assertCount(1, $cart->fresh()->lines);
    }

    public function test_adding_items_with_different_options_creates_distinct_lines(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);

        $item1 = new CartLineItemData(
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
            options: ['color' => 'blue']
        );

        $item2 = new CartLineItemData(
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
            options: ['color' => 'red']
        );

        $line1 = $this->cartService->addLine($cart, $item1);
        $line2 = $this->cartService->addLine($cart, $item2);

        $this->assertNotSame($line1->id, $line2->id);
        $this->assertCount(2, $cart->fresh()->lines);
    }

    public function test_guest_to_customer_cart_merge(): void
    {
        $rawGuestToken = bin2hex(random_bytes(32));
        $guestCtx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            guestToken: $rawGuestToken
        );
        $guestCart = $this->cartService->getOrCreateActiveCart($guestCtx);

        $this->cartService->addLine($guestCart, new CartLineItemData(
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(2)
        ));
        $this->cartService->applyCoupon($guestCart, 'SAVE10');

        $custCtx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $custCart = $this->cartService->getOrCreateActiveCart($custCtx);

        $this->cartService->addLine($custCart, new CartLineItemData(
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $merged = $this->cartService->mergeGuestCart($guestCart, $custCart);

        $this->assertSame($custCart->id, $merged->id);
        $this->assertCount(1, $merged->lines);
        $this->assertSame('3', (string) $merged->lines->first()->quantity);
        $this->assertSame('SAVE10', $merged->coupon_code);
        $this->assertSame('converted', $guestCart->fresh()->status);
    }
}
