<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Context\DTOs\UserContext;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Exceptions\CartAccessDeniedException;
use Modules\Cart\Services\CartOwnershipService;
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

    private User $user1;

    private User $user2;

    private Product $physicalProduct;

    private Product $digitalProduct;

    private CartServiceInterface $cartService;

    private CartOwnershipService $ownershipService;

    private ContextManager $contextManager;

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

        $this->user1 = User::factory()->create();
        $this->user2 = User::factory()->create();

        $this->physicalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'TSHIRT-1',
            'name' => 'T-Shirt',
            'slug' => 't-shirt',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 0.5,
        ]);

        $this->digitalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'EBOOK-1',
            'name' => 'E-Book',
            'slug' => 'e-book',
            'product_type' => 'digital',
            'status' => 'active',
        ]);

        $this->cartService = app(CartServiceInterface::class);
        $this->contextManager = app(ContextManager::class);
        $this->ownershipService = app(CartOwnershipService::class);
    }

    public function test_guest_cart_creation_hashes_token_at_rest_and_never_stores_plaintext(): void
    {
        $rawToken = bin2hex(random_bytes(32));
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

    public function test_same_tenant_idor_protection_for_customer_cart(): void
    {
        $ctx1 = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user1->id
        );
        $cart1 = $this->cartService->getOrCreateActiveCart($ctx1);

        // User 2 context in same tenant
        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));
        $this->contextManager->setUser(UserContext::from($this->user2->id, 'u2@example.com'));

        $this->expectException(CartAccessDeniedException::class);
        $this->ownershipService->verifyOwnership($cart1);
    }

    public function test_same_tenant_idor_protection_for_guest_cart(): void
    {
        $rawToken1 = 'valid-guest-token-12345';
        $ctx1 = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            guestToken: $rawToken1
        );
        $guestCart = $this->cartService->getOrCreateActiveCart($ctx1);

        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));

        // Attempt verification with attacker's token
        $this->expectException(CartAccessDeniedException::class);
        $this->ownershipService->verifyOwnership($guestCart, 'attacker-token-999');
    }

    public function test_authenticated_customer_cart_uniqueness(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user1->id
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
            userId: $this->user1->id
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
            userId: $this->user1->id
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
            options: ['size' => 'L', 'color' => 'blue']
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
            userId: $this->user1->id
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

    public function test_guest_to_customer_cart_merge_with_ownership_verification(): void
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
            userId: $this->user1->id
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
