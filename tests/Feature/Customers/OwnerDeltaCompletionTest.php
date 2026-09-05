<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Core\Channels\Models\Channel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\ChannelContext;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Context\DTOs\MarketContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\Storefront\GiftRegistryPublicPage;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\GiftRegistryPurchase;
use Modules\Customers\Models\Wishlist;
use Modules\Customers\Notifications\PriceDropDetected;
use Modules\Customers\Services\AlertSubscriptionService;
use Modules\Customers\Services\GiftRegistryService;
use Modules\Customers\Services\SaveForLaterService;
use Modules\Customers\Services\WishlistService;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Messaging\Models\ConversationParticipant;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\MessageAttachment;
use Modules\Messaging\Services\ConversationService;
use Modules\Messaging\Services\MessageAttachmentService;
use Modules\Messaging\Services\MessagingService;
use Modules\Pricing\Events\PriceChanged;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Tests\Support\OrderTestFixtures;
use Tests\TestCase;

/**
 * Phase-17 completion delta §6 (Owner Delta A-L) — proves each of the
 * previously-failing items now behaves correctly, not merely that the
 * schema changed.
 */
class OwnerDeltaCompletionTest extends TestCase
{
    use OrderTestFixtures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'owner-delta-tenant', 'name' => 'Owner Delta Tenant', 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
    }

    // ── B: Guest wishlist merge is transactional and idempotent ───────────

    public function test_guest_wishlist_merges_into_user_wishlist_and_running_it_twice_never_duplicates(): void
    {
        $wishlistService = app(WishlistService::class);
        $product = $this->createProduct('OD-WISH-SKU-1');
        $user = $this->createUser();

        $guestSessionId = 'guest-session-'.uniqid();
        $guestWishlist = $wishlistService->defaultWishlistForSession($guestSessionId);
        $wishlistService->addItem($guestWishlist, $product->id);

        $wishlistService->mergeGuestWishlist($user, $guestSessionId);
        $wishlistService->mergeGuestWishlist($user, $guestSessionId); // idempotent re-run

        $userWishlist = $wishlistService->defaultWishlistFor($user);
        $this->assertCount(1, $userWishlist->items()->where('product_id', $product->id)->get());
        $this->assertSame(0, Wishlist::query()->where('session_id', $guestSessionId)->count());
    }

    // ── D: Save for Later price snapshot always includes currency ─────────

    public function test_saved_for_later_item_always_carries_a_currency(): void
    {
        $product = $this->createProduct('OD-SFL-SKU-1');
        $user = $this->createUser();

        $item = app(SaveForLaterService::class)->saveForLater($user, $product->id, null, 1, 5000, 'EUR');

        $this->assertSame('EUR', $item->currency);
    }

    // ── E: Price drop evaluation re-resolves the authoritative price ──────

    public function test_price_drop_notification_uses_the_re_resolved_price_not_the_event_payload(): void
    {
        Notification::fake();

        $product = $this->createProduct('OD-PRICE-SKU-1');
        $user = $this->createUser();

        $priceBook = PriceBook::create([
            'tenant_id' => $this->tenant->id, 'code' => 'default', 'name' => 'Default', 'currency' => 'USD', 'priority' => 0, 'is_default' => true, 'status' => 'active',
        ]);
        Price::create([
            'tenant_id' => $this->tenant->id, 'price_book_id' => $priceBook->id, 'product_id' => $product->id,
            'amount_minor' => 8000, 'currency' => 'USD', 'status' => 'active',
        ]);

        app(AlertSubscriptionService::class)->subscribeToPriceDrop($user, $product->id, null, baselinePriceMinor: 10000, currency: 'USD');

        // Deliberately wrong payload amount (9500) — if the listener trusted
        // this instead of re-resolving, the notification would carry 9500.
        PriceChanged::dispatch($this->tenant->id, $product->id, null, $priceBook->id, 10000, 9500, 'USD');

        Notification::assertSentTo($user, PriceDropDetected::class, function ($notification) {
            return $notification->newAmountMinor === 8000;
        });
    }

    // ── F: Gift registry attribution has a real Cart write path + idempotency ──

    public function test_gift_registry_public_page_writes_gift_registry_item_id_onto_the_cart_line(): void
    {
        [$store, $market, $channel] = $this->setupStoreContext();
        $product = $this->createProduct('OD-REGISTRY-SKU-1');
        $product->update(['status' => 'active']);
        $owner = $this->createUser();

        $registry = app(GiftRegistryService::class)->create($owner, 'Wedding Registry', 'wedding', null);
        $item = app(GiftRegistryService::class)->addItem($registry, $product->id, null, 1);

        Livewire::test(GiftRegistryPublicPage::class, ['shareToken' => $registry->share_token])
            ->call('buyItem', $item->id);

        $cartLine = CartLine::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($cartLine);
        $this->assertSame($item->id, $cartLine->customizations['gift_registry_item_id'] ?? null);
    }

    public function test_recording_a_gift_registry_purchase_twice_for_the_same_order_item_is_idempotent(): void
    {
        $product = $this->createProduct('OD-REGISTRY-SKU-2');
        $owner = $this->createUser();
        $buyer = $this->createUser();

        $registry = app(GiftRegistryService::class)->create($owner, 'Baby Registry', 'baby', null);
        $item = app(GiftRegistryService::class)->addItem($registry, $product->id, null, 5);

        $orderItem = $this->createCompletedOrderWithItem($this->tenant, $buyer, $product);

        $service = app(GiftRegistryService::class);
        $service->recordPurchase($item, $orderItem->order_id, $orderItem->id, $buyer->id, 1);
        $service->recordPurchase($item, $orderItem->order_id, $orderItem->id, $buyer->id, 1);

        $item->refresh();
        $this->assertSame(1, $item->quantity_purchased);
        $this->assertSame(1, GiftRegistryPurchase::query()->where('order_item_id', $orderItem->id)->count());
    }

    // ── G: Messaging send-retry idempotency + non-regressing markRead ─────

    public function test_sending_with_the_same_client_message_id_twice_returns_the_existing_message(): void
    {
        [$vendor, $buyer] = $this->createVendorAndBuyer();
        $conversation = app(ConversationService::class)->startBuyerVendorConversation($this->tenant->id, null, $buyer, $vendor->id);

        $clientId = (string) Str::uuid();
        $service = app(MessagingService::class);

        $first = $service->send($conversation, $buyer, 'Hello', clientMessageId: $clientId);
        $second = $service->send($conversation, $buyer, 'Hello (retry)', clientMessageId: $clientId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_mark_read_never_moves_last_read_at_backwards(): void
    {
        [$vendor, $buyer] = $this->createVendorAndBuyer();
        $conversation = app(ConversationService::class)->startBuyerVendorConversation($this->tenant->id, null, $buyer, $vendor->id);

        $service = app(MessagingService::class);
        $future = now()->addHour();

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)->where('user_id', $buyer->id)
            ->update(['last_read_at' => $future]);

        $service->markRead($conversation, $buyer);

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)->where('user_id', $buyer->id)->first();

        $this->assertSame($future->format('Y-m-d H:i:s'), $participant->last_read_at->format('Y-m-d H:i:s'));
    }

    // ── L: Private media is authorized before a signed URL is returned ────

    public function test_message_attachment_is_rejected_for_a_non_participant_before_any_url_is_generated(): void
    {
        [$vendor, $buyer] = $this->createVendorAndBuyer();
        $stranger = $this->createUser();

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($this->tenant->id, null, $buyer, $vendor->id);
        $message = app(MessagingService::class)->send($conversation, $buyer, 'Attachment test');
        $media = app(MessageAttachmentService::class)->attach($message, UploadedFile::fake()->image('photo.jpg'));
        $attachment = MessageAttachment::query()->create([
            'message_id' => $message->id,
            'media_id' => $media->id,
            'created_at' => now(),
        ]);

        $this->actingAs($stranger);

        $response = $this->get(route('storefront.message-attachments.show', $attachment));
        $response->assertForbidden();
    }

    public function test_message_attachment_is_served_via_signed_url_for_an_authorized_participant(): void
    {
        [$vendor, $buyer] = $this->createVendorAndBuyer();

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($this->tenant->id, null, $buyer, $vendor->id);
        $message = app(MessagingService::class)->send($conversation, $buyer, 'Attachment test');
        $media = app(MessageAttachmentService::class)->attach($message, UploadedFile::fake()->image('photo.jpg'));
        $attachment = MessageAttachment::query()->create([
            'message_id' => $message->id,
            'media_id' => $media->id,
            'created_at' => now(),
        ]);

        $this->actingAs($buyer);

        $response = $this->get(route('storefront.message-attachments.show', $attachment));
        $response->assertRedirect();
    }

    /**
     * @return array{0: Vendor, 1: User}
     */
    private function createVendorAndBuyer(): array
    {
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic', 'code' => 'basic-'.uniqid()]);
        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Delta Vendor',
            'platform_slug' => 'delta-vendor-'.uniqid(), 'legal_name' => 'Delta Vendor Corp', 'email' => 'deltavendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);
        $buyer = $this->createUser();

        return [$vendor, $buyer];
    }

    private function createUser(): User
    {
        return User::create(['name' => 'Test User', 'email' => 'owner-delta-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
    }

    private function createProduct(string $sku): Product
    {
        return app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: $sku, translations: ['en' => ['name' => $sku]],
        ));
    }

    /**
     * @return array{0: Store, 1: Market, 2: Channel}
     */
    private function setupStoreContext(): array
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'owner-delta-store-'.uniqid(), 'status' => 'active']);
        $market = Market::create([
            'tenant_id' => $this->tenant->id, 'code' => 'US', 'name' => 'United States',
            'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'timezone' => 'America/New_York', 'is_active' => true,
        ]);
        $channel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'owner-delta-web-'.uniqid(), 'is_active' => true]);
        $store->channels()->attach($channel->id, ['is_active' => true, 'is_default' => true]);

        app(ContextManager::class)->setStore(StoreContext::from($store->id, $store->slug));
        app(ContextManager::class)->setMarket(MarketContext::from($market->id, $market->code));
        app(ContextManager::class)->setChannel(ChannelContext::from((int) $channel->id, $channel->handle));
        app(ContextManager::class)->setCurrency(CurrencyContext::from('USD'));

        return [$store, $market, $channel];
    }
}
