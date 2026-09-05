# Customers Module Specification

**Module Namespace**: `Modules\Customers`
**Root Path**: `modules/Customers/`
**Status**: Active Production Module (PHASE-17)

---

## 1. Overview & Architectural Boundaries

Owns the customer-engagement domain: `CustomerProfile` (the storefront identity prerequisite, additive 1:1-per-tenant with `App\Models\User`), Wishlist, Recently Viewed, Save for Later, Follow (Product/Vendor), Price Drop/Back-in-Stock alert subscriptions, and Gift Registry.

**Never owns**: authoritative price (Pricing), authoritative stock (Inventory), Order data. Customer Engagement only *observes* Pricing/Inventory via new domain events (`Modules\Pricing\Events\PriceChanged`, `Modules\Inventory\Events\StockReplenished`) and never mutates or independently recalculates commercial values.

**Identity boundary**: `CustomerProfile` is strictly additive to `App\Models\User` — no second authentication system. A `CustomerProfile` row is created lazily (`CustomerProfileService::firstOrCreateFor()`), never for Control Center staff/vendor-staff/super-admin logins. Integrates with the pre-existing (previously unwired) `App\Core\Customers\CustomerScopeService` for store-level customer access under `store_isolated` tenants.

## 2. Directory Layout

```
modules/Customers/
├── Contracts/SaveForLaterServiceInterface.php   # the one Cart<->Customers two-way seam
├── Jobs/PruneGuestRecentlyViewedItemsJob.php
├── Listeners/{CheckPriceDropSubscriptions,CheckBackInStockSubscriptions,
│              RecordGiftRegistryPurchasesOnOrderCompletion}.php
├── Models/{CustomerProfile,Wishlist,WishlistItem,RecentlyViewedItem,
│           SavedForLaterItem,ProductFollow,VendorFollow,
│           PriceDropSubscription,BackInStockSubscription,
│           GiftRegistry,GiftRegistryItem,GiftRegistryPurchase}.php
├── Notifications/{PriceDropDetected,BackInStockDetected}.php
└── Services/{CustomerProfileService,WishlistService,FollowService,
              RecentlyViewedService,SaveForLaterService,
              AlertSubscriptionService,GiftRegistryService}.php
```

## 3. Guest vs. Account Boundary

Wishlist and Recently Viewed are genuinely guest-capable: `wishlists`/`recently_viewed_items` both carry a nullable `user_id` XOR nullable `session_id` (Postgres CHECK constraint + partial unique indexes — the same guest-identity shape for both tables). `WishlistService::mergeGuestWishlist()` runs inside `AuthenticatedSessionController::store()` on every login, moving a guest session's wishlist items onto the user's own default wishlist — transactional and idempotent (`addItem()` is a `firstOrCreate` against the item-uniqueness constraint, so re-running the merge never duplicates), then deletes the guest wishlist row. Compare is deliberately session-only and never persisted at all, even for authenticated users (owner-confirmed scope decision). Follow, Alerts, Save for Later, Gift Registry (create/own) all require an account.

## 4. Event-Driven Alerts

`price_drop_subscriptions`/`back_in_stock_subscriptions` are notified via queued listeners reacting to `PriceChanged`/`StockReplenished` — never a polling/diff job. Dedup uses an atomic conditional `UPDATE ... WHERE notified_at IS NULL`. Alerts are one-shot (`is_active=false` after notifying). A price-drop subscription stores its own `store_id`/`channel_id`/`market_id`/`currency` context; `CheckPriceDropSubscriptions` re-resolves the *current* authoritative price through `Modules\Pricing\Contracts\PriceResolverInterface` using that stored context on every check — the `PriceChanged` event's own payload amount is only the trigger to re-check, never the trusted source of the price a customer is notified about. Customers never calculates a price itself.

## 5. Gift Registry Purchase Tracking

Derived from `Modules\Order\Events\OrderStatusChanged` (dimension=`order_status`, toStatus=`completed`), matched only when the order line explicitly carries `gift_registry_item_id` in `OrderItem.customization_metadata_snapshot`. The real write path: `App\Livewire\Storefront\GiftRegistryPublicPage::buyItem()` adds a Cart line via `CartLineItemData::$customizations['gift_registry_item_id']`, which `CheckoutOrchestrator` already copies verbatim into the CheckoutSession line snapshot, and `OrderSnapshotValidator` already falls back to `customizations` when building `customization_metadata` — no new Cart/Checkout/Order code was needed, only the storefront action that sets the key. `gift_registry_purchases.order_item_id` is uniquely constrained and `GiftRegistryService::recordPurchase()` checks for an existing row first, so a duplicate `OrderStatusChanged` delivery for the same order never double-records or double-increments `quantity_purchased`.

## 6. Database

See `database/migrations/2026_09_05_000010_create_customer_profiles_table.php` and `..._000011_create_customer_engagement_tables.php`. Every table `tenant_id`-scoped via `BelongsToTenant`. Postgres-specific `COALESCE(variant_id, 0)`-based unique indexes handle NULL-uniqueness correctly. `saved_for_later_items.currency` is required alongside `unit_price_minor_snapshot` — a minor-unit integer alone is ambiguous across a multi-currency tenant.

## 7. Storefront Surfaces

Wishlist/Follow/Price-Drop-Alert/Back-in-Stock-Alert/Compare actions live directly on `App\Livewire\Storefront\ProductPage` (and `VendorStorefrontPage` for Follow Vendor) rather than separate pages, per the "reuse an existing surface" instruction. Dedicated account pages exist only where there's genuinely no better host surface: `/account/wishlist`, `/account/recently-viewed`, `/account/gift-registries[/​{registry}]`, `/registry/{shareToken}` (public), `/compare`. Save for Later lives on the Cart page (`/cart`) — a "Save for later" action per line, plus a "Saved for Later" section with "Move to Cart".

## 8. Tests

`tests/Feature/Customers/{CustomerEngagementTest,PriceDropAlertTest,BackInStockAlertTest,GiftRegistryPurchaseTrackingTest,OwnerDeltaCompletionTest}.php`, `tests/Feature/Auth/{CustomerRegistrationTest,PasswordResetAndEmailVerificationTest}.php`, `tests/Feature/Storefront/Phase17StorefrontPagesTest.php`.
