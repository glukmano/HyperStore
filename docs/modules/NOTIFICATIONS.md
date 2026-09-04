# Notifications Boundary Specification

**Status**: Thin boundary introduced in PHASE-17 (no dedicated `modules/Notifications/` directory — notification classes live alongside the domain that raises them, e.g. `Modules\Customers\Notifications\*`, per Laravel's standard convention)

---

## 1. Scope

The smallest first-party notification boundary, per explicit owner instruction — not an omnichannel platform. Exactly two channels: `database` (in-app) and `mail`. Standard Laravel `Illuminate\Notifications\Notification` classes on the already-`Notifiable` `App\Models\User`.

## 2. What Exists

- `Modules\Customers\Notifications\{PriceDropDetected,BackInStockDetected}` — dispatched by the alert-check listeners.
- Laravel's own built-in `Illuminate\Auth\Notifications\{ResetPassword,VerifyEmail}` — reused as-is for the Phase-17 identity flows, never reimplemented.

## 3. Explicitly Excluded

SMS, WhatsApp, push/Firebase/APNs, marketing campaigns. Notification records are never authoritative for engagement state — e.g. `back_in_stock_subscriptions.notified_at` is the authoritative dedup flag, not the notification's own delivery record.

## 4. Future Extension

A dedicated `Modules\Notifications\Services\NotificationPreferenceGate` consulting `CustomerProfile.notification_preferences` (JSONB) before using a channel is a documented, not-yet-built extension point.
