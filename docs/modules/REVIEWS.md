# Reviews Module Specification

**Module Namespace**: `Modules\Reviews`
**Root Path**: `modules/Reviews/`
**Status**: Active Production Module (PHASE-17)

---

## 1. Overview

Owns Product Reviews, Vendor Reviews, verified-purchase derivation, moderation, replies (vendor-staff-only — Reviews never becomes a second Messaging surface), rating aggregates, and Product Q&A.

## 2. Verified Purchase — Never a Client-Supplied Boolean

`Modules\Reviews\Services\VerifiedPurchaseResolver` is the **sole** code path deriving `is_verified_purchase`:

- `isVerifiedForProduct()`: real `OrderItem` row for this product, on an `Order` the reviewer placed, with `order_status = completed`.
- `isVerifiedForVendor()`: prefers the vendor-split `SellerOrder.status = completed` when a marketplace split exists (via `OrderItem.sellerOrderItem.sellerOrder`), falling back to the parent `Order.order_status` for non-marketplace tenant-direct sales.

Computed once at submission time and snapshotted on the review row (`OrderItem` is itself immutable, so re-querying on every render would be wasted work, not extra correctness).

## 3. Schema (two explicit tables per concern, not one polymorphic table)

`product_reviews` / `product_review_replies` / `product_rating_aggregates` and `vendor_reviews` / `vendor_review_replies` / `vendor_rating_aggregates` — kept separate because Product and Vendor reviews have genuinely divergent fields (`variant_id` vs. `communication_rating`/`shipping_rating`) and a polymorphic column cannot carry a real FK constraint.

`product_questions` / `product_answers` for Q&A — structurally separate from Messaging (public/product-scoped vs. private/conversation-scoped).

## 4. Rating Aggregates — Deterministic, Safely Recomputable

Never a DB trigger (invisible to Larastan/Pest). `Modules\Reviews\Services\RatingAggregateService::recomputeForProduct()/recomputeForVendor()` runs on every approve/retract via `ProductReviewApproved`/`ProductReviewRetracted`/`VendorReviewApproved`/`VendorReviewRetracted` listeners, and via a nightly `RecomputeAllRatingAggregatesJob` full-reconciliation safety net. Stored in dedicated `product_rating_aggregates`/`vendor_rating_aggregates` tables owned by Reviews — never new columns on Catalog's `products` or Marketplace's `vendors` tables. Read via `Modules\Reviews\Contracts\RatingAggregateReaderInterface` (a legitimate cross-module read contract).

## 5. Media & Sanitization

`ReviewMediaService` mirrors `Modules\Catalog\Services\CatalogMediaService`'s exact shape (`review_photos`/`review_videos` MediaLibrary collections). Review/Q&A text uses the shared `App\Core\Support\Contracts\ContentSanitizerInterface`.

## 6. Storefront & Control Center Surfaces

`App\Livewire\Storefront\{ProductReviewsSection,ProductQaSection,VendorReviewsSection}` are embedded directly into the product page (`/p/{sku}`) and vendor storefront page (`/vendor/{slug}`) rather than separate pages — list + submit form + verified-purchase badge + seller replies, all in one place. Control Center moderation: `Modules\Reviews\Livewire\{ReviewModerationManager,VendorReviewModerationManager,QaModerationManager}` (`/control-center/platform/{reviews,vendor-reviews,qa}`, `reviews.view`/`reviews.moderate`/`qa.moderate`).

## 7. Tests

`tests/Feature/Reviews/{ProductReviewTest,VendorReviewTest,ProductQaTest}.php` — covers both `SellerOrder`-present and -absent verified-purchase branches, one-review-per-user uniqueness, and moderation-triggered aggregate recompute. `tests/Feature/Storefront/Phase17StorefrontPagesTest.php` and `tests/Feature/ControlCenter/Phase17ControlCenterScreensTest.php` cover the storefront/Control-Center surfaces above.
