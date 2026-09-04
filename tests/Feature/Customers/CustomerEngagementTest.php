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
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\ProductFollow;
use Modules\Customers\Models\RecentlyViewedItem;
use Modules\Customers\Services\FollowService;
use Modules\Customers\Services\GiftRegistryService;
use Modules\Customers\Services\RecentlyViewedService;
use Modules\Customers\Services\WishlistService;
use Tests\TestCase;

class CustomerEngagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'engagement-tenant', 'name' => 'Engagement Tenant', 'status' => 'active']);
        Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'engagement-store', 'status' => 'active']);

        $this->user = User::create(['name' => 'Cust', 'email' => 'cust-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'ENGAGE-SKU-1',
            translations: ['en' => ['name' => 'Engagement Product']],
        ));

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
    }

    public function test_wishlist_item_is_unique_per_product_and_variant(): void
    {
        $service = app(WishlistService::class);
        $wishlist = $service->defaultWishlistFor($this->user);

        $item1 = $service->addItem($wishlist, $this->product->id);
        $item2 = $service->addItem($wishlist, $this->product->id);

        $this->assertSame($item1->id, $item2->id);
        $this->assertSame(1, $wishlist->items()->count());
    }

    public function test_wishlist_item_can_be_removed(): void
    {
        $service = app(WishlistService::class);
        $wishlist = $service->defaultWishlistFor($this->user);
        $service->addItem($wishlist, $this->product->id);

        $service->removeItem($wishlist, $this->product->id);

        $this->assertSame(0, $wishlist->items()->count());
    }

    public function test_a_user_has_exactly_one_default_wishlist(): void
    {
        $service = app(WishlistService::class);

        $first = $service->defaultWishlistFor($this->user);
        $second = $service->defaultWishlistFor($this->user);

        $this->assertSame($first->id, $second->id);
    }

    public function test_product_follow_is_unique_per_user_and_product(): void
    {
        $service = app(FollowService::class);

        $service->followProduct($this->user, $this->product->id);
        $service->followProduct($this->user, $this->product->id);

        $this->assertSame(1, ProductFollow::query()->where('user_id', $this->user->id)->where('product_id', $this->product->id)->count());
        $this->assertTrue($service->isFollowingProduct($this->user, $this->product->id));
    }

    public function test_unfollowing_a_product_removes_the_follow_record(): void
    {
        $service = app(FollowService::class);
        $service->followProduct($this->user, $this->product->id);

        $service->unfollowProduct($this->user, $this->product->id);

        $this->assertFalse($service->isFollowingProduct($this->user, $this->product->id));
    }

    public function test_recently_viewed_deduplicates_and_increments_view_count(): void
    {
        $service = app(RecentlyViewedService::class);

        $service->recordView($this->product->id, $this->user, null);
        $service->recordView($this->product->id, $this->user, null);
        $service->recordView($this->product->id, $this->user, null);

        $item = RecentlyViewedItem::query()->where('user_id', $this->user->id)->where('product_id', $this->product->id)->sole();
        $this->assertSame(3, $item->view_count);
    }

    public function test_recently_viewed_supports_guest_session_scoping_independent_of_authenticated_rows(): void
    {
        $service = app(RecentlyViewedService::class);

        $service->recordView($this->product->id, null, 'guest-session-abc');
        $service->recordView($this->product->id, $this->user, null);

        $this->assertSame(1, RecentlyViewedItem::query()->whereNull('user_id')->where('session_id', 'guest-session-abc')->count());
        $this->assertSame(1, RecentlyViewedItem::query()->where('user_id', $this->user->id)->count());
    }

    public function test_recently_viewed_trims_to_the_retention_limit(): void
    {
        $service = app(RecentlyViewedService::class);

        for ($i = 0; $i < 55; $i++) {
            $product = app(CreateProductAction::class)->execute(new ProductData(
                tenantId: $this->tenant->id,
                productType: 'physical',
                sku: 'RETENTION-SKU-'.$i,
                translations: ['en' => ['name' => 'Retention Product '.$i]],
            ));
            $service->recordView($product->id, $this->user, null);
        }

        $this->assertSame(50, RecentlyViewedItem::query()->where('user_id', $this->user->id)->count());
    }

    public function test_gift_registry_item_purchase_progress_is_bounded_by_requested_quantity(): void
    {
        $registryService = app(GiftRegistryService::class);
        $registry = $registryService->create($this->user, 'Wedding Registry', 'wedding', null);
        $item = $registryService->addItem($registry, $this->product->id, null, 2);

        $this->assertSame(2, $item->remainingQuantity());
        $this->assertFalse($item->isFullyPurchased());
    }
}
