# Messaging Module Specification

**Module Namespace**: `Modules\Messaging`
**Root Path**: `modules/Messaging/`
**Status**: Active Production Module (PHASE-17)

---

## 1. Overview

First-party, realtime Buyer↔Seller messaging using Laravel Reverb (`^1.11`, Pusher-protocol-compatible). **Persistent database data is authoritative; Reverb transport is not** — the single highest-risk invariant in Phase-17, since this is the only module requiring genuinely new production infrastructure (a supervised Reverb process).

## 2. Persistence-Before-Broadcast (hard invariant)

`Modules\Messaging\Services\MessagingService::send()`: writes `messages`/`message_attachments`/`conversations.last_message_at` inside `DB::transaction()`, **commits**, then dispatches `Modules\Messaging\Events\MessageSent implements ShouldBroadcast` only via `DB::afterCommit()`. By the time any listener/broadcast fires, the row is already durably queryable — proven directly in `tests/Feature/Messaging/MessagingPersistenceTest.php`.

## 3. Authorization — One Policy, Two Callers

`Modules\Messaging\Services\ConversationPolicy` is the single authorization surface. Both Livewire components and `routes/channels.php`'s private-channel callback (`Broadcast::channel('conversation.{id}', ...)`) call the exact same `view()`/`sendMessage()`/`close()` methods — never duplicated logic. A buyer must be a `conversation_participants` row; vendor staff must have an active `VendorUser` row for the conversation's `vendor_id`; tenant staff need active `TenantUser` membership.

## 4. Schema

`conversations` / `conversation_participants` / `messages` / `message_attachments`. Read state is a per-participant `last_read_at` timestamp (not per-message receipts — an intentional Phase-17 scope-down). `context_type`/`context_id` on `conversations` is a loose, non-authoritative display-only reference ("regarding Order #123"), never used for authorization.

`messages.client_message_id` (UUID) is uniquely constrained together with `(conversation_id, sender_user_id)` — a client-generated id surviving a network retry. `MessagingService::send()` checks for an existing row on that key first and returns it unchanged rather than creating a duplicate; when the caller omits it, a fresh UUID is generated (matching the prior at-most-once-per-call behavior). `MessagingService::markRead()` only ever advances `last_read_at` forward — the update is guarded by `WHERE last_read_at IS NULL OR last_read_at < now()`, so an out-of-order or duplicate call can never move it backwards.

## 5. Attachment Security

`Modules\Messaging\Services\MessageAttachmentService`: MIME validated via `UploadedFile::getMimeType()` (real finfo content inspection, never client-declared type/extension), server-generated filenames, size-capped, stored on MediaLibrary's `local` (private) disk. The one HTTP path that ever hands out a signed temporary URL is `App\Http\Controllers\Storefront\MessageAttachmentController::show()` (`/message-attachments/{attachment}`) — it checks `ConversationPolicy::view()` **before** calling `Media::getTemporaryUrl()`, never treating a hard-to-guess URL as authorization by itself.

## 6. Rate Limiting

`MessagingService::send()` enforces a 20-messages-per-minute-per-sender limit via Laravel's `RateLimiter` facade, throwing `MessageRateLimitExceededException`.

## 7. Storefront & Control Center Surfaces

`App\Livewire\Storefront\Account\{MessagesInbox,ConversationThread}` (`/account/messages[/{conversation}]`, auth-gated) — the thread view polls every 5s (`wire:poll`) so it stays correct even before a dedicated Echo/JS listener is wired to Reverb's real-time broadcast; the backend's persistence-then-broadcast guarantee holds either way. `Modules\Messaging\Livewire\MessagingModerationManager` (`/control-center/platform/messaging`, `messaging.moderate`) lists conversations tenant-wide and can close one — deliberately bypassing `ConversationPolicy`/`MessagingService::close()` (that path is participant self-service requiring a `conversation_participants` row) in favor of the `messaging.moderate` permission gate alone.

## 8. Tests

`tests/Feature/Messaging/{MessagingAuthorizationTest,MessagingPersistenceTest,ConversationChannelAuthTest,MessageAttachmentTest,MessageRateLimitTest}.php`, `tests/Feature/Customers/OwnerDeltaCompletionTest.php` (send-retry idempotency, non-regressing `markRead`, attachment-authorization-before-URL), `tests/Feature/ControlCenter/Phase17ControlCenterScreensTest.php` (moderation screen). `ConversationChannelAuthTest` proves the real `/broadcasting/auth` HTTP endpoint (not just the policy in isolation) correctly authorizes/rejects, using the `reverb` driver re-registered onto that test's active broadcaster instance (channels are registered onto whichever driver is the boot-time default; the suite-wide default is `log`, whose `auth()` is a no-op, so this test explicitly swaps drivers and re-registers the production channel definition to exercise the real Pusher-protocol auth-response path).
