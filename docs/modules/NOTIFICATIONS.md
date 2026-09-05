# Notifications Boundary Specification

**Module Namespace**: `Modules\Notifications`
**Root Path**: `modules/Notifications/`
**Status**: Active thin module (restored in the Phase-17 completion delta — see §4 of the delta report)

---

## 1. Scope

The smallest first-party notification boundary, per explicit owner instruction — not an omnichannel platform. Exactly two channels: `database` (in-app) and `mail`. Standard Laravel `Illuminate\Notifications\Notification` classes on the already-`Notifiable` `App\Models\User`.

## 2. Module Boundary — What Lives Here vs. What Stays in Domain Modules

Domain-specific Laravel Notification classes remain near their owning business domain (e.g. `Modules\Customers\Notifications\{PriceDropDetected,BackInStockDetected}`) — this module never owns notification *content*. `modules/Notifications/` owns only the shared, cross-cutting concerns:

- `Contracts\NotificationPreferenceGateInterface` — decides whether a channel is allowed for a given user + notification type.
- `Contracts\HasNotificationChannels` — the explicit, statically-checkable contract (`via(object): list<string>`) a Notification class must implement to be dispatchable via `NotificationDispatchService` (Laravel's own `Notification` base class does not declare `via()` in its type signature — subclasses define it by convention only).
- `Services\NotificationPreferenceGate` — reads `CustomerProfile.notification_preferences` (JSONB, keyed by notification type → channel → bool); defaults to opted-in (never opt-in-by-default-false) so a customer who has never touched preferences receives every notification exactly as before this delta.
- `Services\NotificationDispatchService` — the one dispatch path domain listeners use instead of `$user->notify()` directly. Filters the notification's own declared `via()` channels through the preference gate, then calls `Notification::sendNow($user, $notification, $allowedChannels)` — Laravel's per-call channel-override parameter, so no Notification class needs to know about preferences itself.
- `NotificationsServiceProvider` + `module.json` — discovered by `ModuleKernel` like every other first-party module; binds `NotificationPreferenceGateInterface`.

## 3. One-Way Dependency Direction

Customers / Reviews / Messaging → their own domain events → their own listeners → `NotificationDispatchService::send()` → Notifications' gate. Notifications never depends back on Customers/Reviews/Messaging beyond reading `CustomerProfile` (a read-only cross-module contract, mirroring how Reviews reads Catalog/Order data).

## 4. Storefront Surface

`App\Livewire\Storefront\Account\NotificationPreferencesPage` (`/account/notifications`, auth-gated) lets a customer toggle mail delivery per notification type; database (in-app) delivery is never gated off (always on) in this Phase-17 scope.

## 5. Explicitly Excluded

SMS, WhatsApp, push/Firebase/APNs, marketing campaigns. Notification records are never authoritative for engagement state — e.g. `back_in_stock_subscriptions.notified_at` is the authoritative dedup flag, not the notification's own delivery record.

## 6. Tests

`tests/Feature/Customers/OwnerDeltaCompletionTest.php` proves the price-drop notification carries the price re-resolved through Pricing, not the raw event payload — the same call path now flows through `NotificationDispatchService`. `tests/Feature/Customers/BackInStockAlertTest.php` and `PriceDropAlertTest.php` cover the dedup/one-shot semantics unaffected by this delta.
