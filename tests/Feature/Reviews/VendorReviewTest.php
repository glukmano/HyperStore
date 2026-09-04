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
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\SellerOrderStatus;
use Modules\Order\Models\SellerOrder;
use Modules\Order\Models\SellerOrderItem;
use Modules\Reviews\Exceptions\ReviewAlreadySubmittedException;
use Modules\Reviews\Models\VendorRatingAggregate;
use Modules\Reviews\Models\VendorReview;
use Modules\Reviews\Services\VendorReviewService;
use Modules\Reviews\Services\VerifiedPurchaseResolver;
use Tests\Support\OrderTestFixtures;
use Tests\TestCase;

/**
 * Covers both verified-purchase resolution branches for Vendor reviews:
 * a marketplace order with a real SellerOrder split, and a non-marketplace
 * tenant-direct sale falling back to the parent Order's own status.
 */
class VendorReviewTest extends TestCase
{
    use OrderTestFixtures;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Product $product;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'vendor-review-tenant', 'name' => 'Vendor Review Tenant', 'status' => 'active']);
        $this->user = User::create(['name' => 'Reviewer', 'email' => 'vreviewer-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'VENDOR-REVIEW-SKU-1',
            translations: ['en' => ['name' => 'Vendor Review Product']],
        ));

        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic Plan', 'code' => 'basic']);
        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Test Vendor',
            'platform_slug' => 'test-vendor-'.uniqid(),
            'legal_name' => 'Test Vendor Corp',
            'email' => 'vendor-'.uniqid().'@example.com',
            'payout_currency' => 'USD',
        ]);
    }

    public function test_non_marketplace_sale_falls_back_to_the_parent_order_status(): void
    {
        // No SellerOrder split at all — tenant-direct sale attributed to the
        // vendor only via OrderItem.vendor_id snapshot.
        $this->createCompletedOrderWithItem($this->tenant, $this->user, $this->product, $this->vendor->id);

        $resolver = app(VerifiedPurchaseResolver::class);
        $this->assertTrue($resolver->isVerifiedForVendor($this->tenant->id, $this->user->id, $this->vendor->id));
    }

    public function test_marketplace_sale_with_a_completed_seller_order_is_verified(): void
    {
        $orderItem = $this->createOrderWithItem($this->tenant, $this->user, $this->product, OrderStatus::PLACED, $this->vendor->id);
        $order = $orderItem->order()->first();

        $sellerOrder = SellerOrder::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'seller_order_number' => 'SO-'.uniqid(),
            'seller_type' => 'vendor',
            'vendor_id' => $this->vendor->id,
            'commercial_model' => 'marketplace',
            'currency' => 'USD',
            'subtotal_minor' => 5000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'shipping_original_minor' => 0,
            'shipping_discount_minor' => 0,
            'shipping_final_minor' => 0,
            'total_minor' => 5000,
            'commission_total_minor' => 0,
            'status' => SellerOrderStatus::COMPLETED->value,
        ]);

        SellerOrderItem::create([
            'tenant_id' => $this->tenant->id,
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $orderItem->id,
            'quantity' => '1.00000000',
            'subtotal_minor' => 5000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 5000,
            'commission_minor' => 0,
        ]);

        // The parent Order itself is still only PLACED — verification must
        // come from the SellerOrder's own completed status, not the parent.
        $resolver = app(VerifiedPurchaseResolver::class);
        $this->assertTrue($resolver->isVerifiedForVendor($this->tenant->id, $this->user->id, $this->vendor->id));
    }

    public function test_marketplace_sale_with_an_open_seller_order_is_not_verified_even_if_parent_order_looks_complete(): void
    {
        $orderItem = $this->createCompletedOrderWithItem($this->tenant, $this->user, $this->product, $this->vendor->id);
        $order = $orderItem->order()->first();

        $sellerOrder = SellerOrder::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'seller_order_number' => 'SO-'.uniqid(),
            'seller_type' => 'vendor',
            'vendor_id' => $this->vendor->id,
            'commercial_model' => 'marketplace',
            'currency' => 'USD',
            'subtotal_minor' => 5000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'shipping_original_minor' => 0,
            'shipping_discount_minor' => 0,
            'shipping_final_minor' => 0,
            'total_minor' => 5000,
            'commission_total_minor' => 0,
            'status' => SellerOrderStatus::OPEN->value,
        ]);

        SellerOrderItem::create([
            'tenant_id' => $this->tenant->id,
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $orderItem->id,
            'quantity' => '1.00000000',
            'subtotal_minor' => 5000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 5000,
            'commission_minor' => 0,
        ]);

        $resolver = app(VerifiedPurchaseResolver::class);
        $this->assertFalse($resolver->isVerifiedForVendor($this->tenant->id, $this->user->id, $this->vendor->id));
    }

    public function test_a_user_can_only_submit_one_review_per_vendor(): void
    {
        $service = app(VendorReviewService::class);
        $service->submit($this->tenant->id, $this->user, $this->vendor->id, 5, 'Great vendor');

        $this->expectException(ReviewAlreadySubmittedException::class);
        $service->submit($this->tenant->id, $this->user, $this->vendor->id, 3, 'Second attempt');
    }

    public function test_approving_a_vendor_review_recomputes_the_vendor_rating_aggregate(): void
    {
        $service = app(VendorReviewService::class);
        $review = $service->submit($this->tenant->id, $this->user, $this->vendor->id, 5, 'Excellent', communicationRating: 5, shippingRating: 4);

        $moderator = User::create(['name' => 'Mod', 'email' => 'vmod-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);
        $service->moderate($review, VendorReview::STATUS_APPROVED, $moderator);

        $aggregate = VendorRatingAggregate::query()->find($this->vendor->id);
        $this->assertNotNull($aggregate);
        $this->assertSame(5.0, (float) $aggregate->average_rating);
    }
}
