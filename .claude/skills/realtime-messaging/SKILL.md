---
name: realtime-messaging
description: Enforces Laravel Reverb websockets, Buyer-Seller messaging, message persistence, authorization, and moderation. Use when working on chat, live notifications, or websocket events.
---

# Realtime Messaging & Buyer-Seller Chat

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 18)

## Core Rules & Mandates

1. **First-Party Realtime Architecture**:
   - Built on **Laravel Reverb** for native WebSocket broadcasting.
   - Primary communication domain: Buyer ↔ Seller / Vendor chat, live order tracking, administrative alerts.
2. **Database Persistence as Source of Truth**:
   - Application database (PostgreSQL) is the durable source of truth.
   - Every message, attachment metadata, and read-receipt must be saved in the database BEFORE broadcasting over WebSockets.
   - WebSockets are transient transport, not data storage.
3. **Channel Authorization & Security**:
   - Private and Presence channels must authorize user participation explicitly (`routes/channels.php`).
   - Validate that buyers can only join conversation channels for their own orders/inquiries.
   - Validate that vendor staff can only access conversations for their assigned vendor account.
4. **Moderation & Abuse Controls**:
   - Implement message rate limiting, forbidden word / contact info filtering where required, file attachment validation, and reporting workflows.

## Pre-Execution Checklist
- [ ] Is message data persisted to PostgreSQL prior to event broadcast?
- [ ] Are WebSocket channel authorization rules strictly defined?
- [ ] Are file attachments scanned and restricted to safe MIME types?

## Forbidden Shortcuts
- ❌ Broadcasting messages without persisting to PostgreSQL.
- ❌ Opening public unauthenticated channels for private customer/vendor chats.
- ❌ Skipping file upload validation on chat attachments.

## Validation Steps
1. Test channel authorization with unauthorized user tokens (expect 403).
2. Verify end-to-end message creation, persistence, broadcast, and read-status update.
3. Test chat rate limiting and spam filtering triggers.
