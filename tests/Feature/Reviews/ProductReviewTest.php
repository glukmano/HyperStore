<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Reviews\Exceptions\ReviewAlreadySubmittedException;
use Modules\Reviews\Models\ProductRatingAggregate;
use Modules\Reviews\Models\ProductReview;
use Modules\Reviews\Services\ProductReviewService;
use Modules\Reviews\Services\VerifiedPurchaseResolver;
use Tests\Support\OrderTestFixtures;
use Tests\TestCase;

/**
 * Proves verified-purchase status is derived exclusively from real
 * Order/OrderItem data (never a client-supplied boolean), one review per
 * product per user, and moderation-triggered rating aggregate recompute.
 */
class ProductReviewTest extends TestCase
{
    use OrderTestFixtures;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'review-tenant', 'name' => 'Review Tenant', 'status' => 'active']);
        $this->user = User::create(['name' => 'Reviewer', 'email' => 'reviewer-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'REVIEW-SKU-1',
            translations: ['en' => ['name' => 'Review Product']],
        ));
    }

    public function test_a_user_with_no_completed_order_is_not_a_verified_purchaser(): void
    {
        $resolver = app(VerifiedPurchaseResolver::class);

        $this->assertFalse($resolver->isVerifiedForProduct($this->tenant->id, $this->user->id, $this->product->id));
    }

    public function test_a_user_with_a_completed_order_containing_the_product_is_a_verified_purchaser(): void
    {
        $this->createCompletedOrderWithItem($this->tenant, $this->user, $this->product);

        $resolver = app(VerifiedPurchaseResolver::class);

        $this->assertTrue($resolver->isVerifiedForProduct($this->tenant->id, $this->user->id, $this->product->id));
    }

    public function test_a_placed_but_not_completed_order_does_not_grant_verified_purchase(): void
    {
        $this->createOrderWithItem($this->tenant, $this->user, $this->product, OrderStatus::PLACED);

        $resolver = app(VerifiedPurchaseResolver::class);

        $this->assertFalse($resolver->isVerifiedForProduct($this->tenant->id, $this->user->id, $this->product->id));
    }

    public function test_submitting_a_review_snapshots_verified_purchase_status_and_never_trusts_client_input(): void
    {
        $this->createCompletedOrderWithItem($this->tenant, $this->user, $this->product);

        $review = app(ProductReviewService::class)->submit(
            $this->tenant->id, $this->user, $this->product->id, 5, 'Great product',
        );

        $this->assertTrue($review->is_verified_purchase);
    }

    public function test_a_user_without_a_purchase_can_still_review_but_is_not_marked_verified(): void
    {
        $review = app(ProductReviewService::class)->submit(
            $this->tenant->id, $this->user, $this->product->id, 4, 'Looks fine',
        );

        $this->assertFalse($review->is_verified_purchase);
    }

    public function test_a_user_can_only_submit_one_review_per_product(): void
    {
        $service = app(ProductReviewService::class);
        $service->submit($this->tenant->id, $this->user, $this->product->id, 5, 'First review');

        $this->expectException(ReviewAlreadySubmittedException::class);
        $service->submit($this->tenant->id, $this->user, $this->product->id, 3, 'Second attempt');
    }

    public function test_approving_a_review_recomputes_the_rating_aggregate(): void
    {
        $service = app(ProductReviewService::class);
        $review = $service->submit($this->tenant->id, $this->user, $this->product->id, 4, 'Solid');

        $moderator = User::create(['name' => 'Mod', 'email' => 'mod-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);
        $service->moderate($review, ProductReview::STATUS_APPROVED, $moderator);

        $aggregate = ProductRatingAggregate::query()->find($this->product->id);
        $this->assertNotNull($aggregate);
        $this->assertSame(4.0, (float) $aggregate->average_rating);
        $this->assertSame(1, $aggregate->review_count);
    }

    public function test_a_pending_review_is_not_counted_in_the_aggregate(): void
    {
        app(ProductReviewService::class)->submit($this->tenant->id, $this->user, $this->product->id, 5, 'Pending review');

        $aggregate = ProductRatingAggregate::query()->find($this->product->id);
        $this->assertNull($aggregate);
    }

    public function test_rejecting_a_previously_approved_review_removes_it_from_the_aggregate(): void
    {
        $service = app(ProductReviewService::class);
        $review = $service->submit($this->tenant->id, $this->user, $this->product->id, 5, 'Great');
        $moderator = User::create(['name' => 'Mod', 'email' => 'mod2-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);

        $service->moderate($review, ProductReview::STATUS_APPROVED, $moderator);
        $service->moderate($review, ProductReview::STATUS_REJECTED, $moderator, 'Spam');

        $aggregate = ProductRatingAggregate::query()->find($this->product->id);
        $this->assertSame(0, $aggregate->review_count);
    }
}
