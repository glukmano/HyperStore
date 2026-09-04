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

Wishlist and Recently Viewed are guest-capable (`session_id`-scoped). Compare is deliberately session-only and never persisted at all, even for authenticated users (owner-confirmable scope decision). Follow, Alerts, Save for Later, Gift Registry (create/own) all require an account.

## 4. Event-Driven Alerts

`price_drop_subscriptions`/`back_in_stock_subscriptions` are notified via queued listeners reacting to `PriceChanged`/`StockReplenished` — never a polling/diff job. Dedup uses an atomic conditional `UPDATE ... WHERE notified_at IS NULL`. Alerts are one-shot (`is_active=false` after notifying).

## 5. Gift Registry Purchase Tracking

Derived from `Modules\Order\Events\OrderStatusChanged` (dimension=`order_status`, toStatus=`completed`), matched only when the order line explicitly carries `gift_registry_item_id` in `OrderItem.customization_metadata_snapshot` — a plain product purchase never counts.

## 6. Database

See `database/migrations/2026_09_05_000010_create_customer_profiles_table.php` and `..._000011_create_customer_engagement_tables.php`. Every table `tenant_id`-scoped via `BelongsToTenant`. Postgres-specific `COALESCE(variant_id, 0)`-based unique indexes handle NULL-uniqueness correctly.

## 7. Tests

`tests/Feature/Customers/{CustomerEngagementTest,PriceDropAlertTest,BackInStockAlertTest,GiftRegistryPurchaseTrackingTest}.php`, `tests/Feature/Auth/{CustomerRegistrationTest,PasswordResetAndEmailVerificationTest}.php`.
