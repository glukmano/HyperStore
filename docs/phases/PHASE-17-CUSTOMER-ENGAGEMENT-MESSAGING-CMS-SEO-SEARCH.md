# PHASE-17: Customer Engagement, Messaging, CMS, SEO & Search

## 1. Objective

Build the first-party customer-experience and content-discovery layer over the existing commerce platform, per `PROJECT_MASTER_PLAN.md` §18. Source-audited and planned in full (owner-approved plan, `bubbly-kindling-grove.md`), then implemented directly per explicit owner authorization to proceed.

## 2. Master §18 Reconciliation

Every capability named in Master §18 is classified A (implement)/B (existing, reused)/C (extension seam only)/D (explicitly deferred). Full matrix in the approved plan §3. Summary: Wishlist, Compare, Recently Viewed, Follow, Price/Stock Alerts, Save for Later, Gift Registry, Q&A, Reviews (Product+Vendor, verified purchase), Messaging, CMS, bounded Page Builder, SEO, Search are **A**. Loyalty, Rewards, Wallet, Store Credit, Gift Cards, full Preorder redesign are **D** — mapped to their own named future modules per Master §7's own target tree (`Wallet/`, `Loyalty/`, `GiftCards/`), not silently dropped. Hreflang/Market-domain/Vendor-domain SEO resolution and AI natural-language search are **C** — real, documented, forward-compatible seams, not fabricated implementations.

## 3. Included Scope

Seven new modules matching Master §7's exact target repository structure — no invented names:

- `modules/Customers/` — `CustomerProfile` (identity prerequisite), Wishlist, Compare (session-only), Recently Viewed, Save for Later, Follow (Product+Vendor), Price Drop/Back-in-Stock alert subscriptions, Gift Registry.
- `modules/Reviews/` — Product Reviews, Vendor Reviews, verified-purchase derivation, replies (vendor-staff-only), moderation, rating aggregates, Product Q&A.
- `modules/Messaging/` — Conversation/Message/Participant, Reverb realtime, channel authorization, attachments.
- `modules/Cms/` — Page, BlogPost, Faq, Menu, Banner, Redirect, bounded Page Builder (`BlockTypeRegistryInterface`, the Plugin SDK's 7th registry).
- `modules/Seo/` — central `SeoMetadataService`, structured-data builders, sitemap, robots.
- `modules/Search/` — one authoritative `SearchServiceInterface`, Scout+Meilisearch adapter, tenant-isolated indexing.
- `modules/Notifications/` — thin boundary: `database`+`mail` channels only, Laravel Notification classes.

Plus: four new storefront auth controllers (`app/Http/Controllers/Auth/{RegisteredUserController,PasswordResetLinkController,NewPasswordController,EmailVerificationPromptController,VerifyEmailController,EmailVerificationNotificationController}`), new Reverb+Meilisearch production infrastructure, `routes/channels.php`, `ADR-0137`.

## 4. Explicitly Excluded Scope

Loyalty, Rewards, Wallet, Store Credit, Gift Card financial issuance/redemption, full Preorder fulfillment redesign, Affiliate, Referral, B2B, Auctions, Booking, subscription billing, digital delivery, POS, Phase-18 Market/Language management (dynamic `Locale` model), SaaS/Licensing, Plugin Marketplace, Theme Marketplace, AI/MCP platform, AI natural-language search implementation, WhatsApp/SMS/push notification channels, custom visual design, React/Vue/Next, microservices. **Sanctum activation (`routes/api.php`, token issuance) is not exercised** — Reverb private-channel auth uses the existing session-authenticated `web` guard; Sanctum remains a dormant, already-installed dependency. Phase-18 is not started.

## 5. Required Skills

`project-governance`, `postgresql-data-design`, `realtime-messaging`, `seo-commerce`, `security-hardening`, `testing-quality`, `theme-sdk`, `plugin-sdk`, `localization-markets-rtl`, `documentation-adr`.

## 6. Prerequisites

Phase-16 closed (`488d887`, `6ddb7df`). `spatie/laravel-medialibrary`, `spatie/laravel-permission`, `spatie/laravel-activitylog`, `AuditManagerInterface`, `ContextManager`/`BelongsToTenant`, `CategoryTranslation`-shaped per-locale content pattern, `OrderItem` immutable snapshot columns, `OrderStatusChanged` event, `SellerOrder`, `NavigationRegistryInterface`, `ThemeRegistry`/`ThemeResolver`, Phase-16 Plugin SDK's six registries — all confirmed present and reused, not rebuilt.

New dependencies installed this phase: `laravel/reverb` (`^1.11`), `laravel/scout` (`^11.6`), `meilisearch/meilisearch-php` (`^1.17`), `stevebauman/purify` (`^6.3`), `laravel-echo` (`^2`), `pusher-js` (`^8`) — all registered in `docs/DEPENDENCIES.md`.

## 7. Architecture & ADRs

- `ADR-0137`: Page Builder Block Type Registry and Rendering Model (the one item new/foundational enough to need its own record — first-ever block/slot concept in the platform).
- No other ADR is pre-committed; additional ADRs are added only if implementation surfaces a genuine non-obvious architectural commitment beyond what this phase file already documents (Customer identity boundary, Messaging persistence/authorization, Search isolation guarantee, CMS/SEO canonical-redirect model are candidates evaluated at implementation time, per the owner's "evaluate, not blindly create" instruction).
- Full module/bounded-context architecture, model shapes, event contracts, and rationale: approved plan `bubbly-kindling-grove.md` §6-§30 (retained verbatim as the implementation reference; this phase file is the acceptance-criteria/status document, not a duplicate of the design).

## 8. Database Work

All tables `tenant_id`-scoped via `BelongsToTenant`, normalized, real FKs, scoped unique constraints, explicit CHECK constraints where needed (e.g. Recently Viewed's guest/auth XOR). JSONB used only for genuinely dynamic fields (`CustomerProfile.notification_preferences`, `PageBlock.config`, `Message.metadata`) — never to avoid modeling a core relationship. Full table list: plan §8 (Customer Engagement), §9 (alert subscriptions), §11 (Reviews/Q&A/rating aggregates), §13 (Messaging), §15 (CMS/Page Builder), §30 (search analytics). Migrations live centrally in `database/migrations/` with module-scoped descriptive filenames, matching the established convention (not per-module migration directories).

## 9. Backend Work

`app/Core/Plugin/`-pattern-following `Modules\Cms\Contracts\BlockTypeRegistryInterface` (7th Plugin SDK registry). New domain events: `Modules\Pricing\Events\PriceChanged` (dispatched from a new, minimal `PriceWriteService` wrapping the one confirmed price write path, `ProductPricingManager::savePrice()`), `Modules\Inventory\Events\StockReplenished` (dispatched from `InventoryAdjustmentService`'s existing locked-row closure, edge-triggered on the `<=0 → >0` available-to-sell transition). `Modules\Reviews\Services\VerifiedPurchaseResolver` deriving eligibility from `OrderItem`/`SellerOrder`/`OrderStatusChanged`. `Modules\Messaging\Services\MessagingService` (persistence-before-broadcast, `DB::afterCommit()`-ordered). `Modules\Search\Contracts\SearchServiceInterface` + `ScoutSearchService` (production) — always injects caller tenant/store/channel context, no unscoped query construction possible. `Modules\Seo\Services\SeoMetadataService` + `StructuredDataBuilderInterface` implementations. `Modules\Customers\Models\CustomerProfile` + four new auth controllers, reusing `web` guard/`User`/`ContextManager` exclusively.

## 10. Frontend Work

New `themes/default/pages/{search-results,cms-page,blog-post,faq,gift-registry}.blade.php`, new `components/{wishlist-button,follow-button,review-card,rating-stars,qa-thread}.blade.php`, all `<x-ui.*>`-only, RTL/LTR-correct via logical utilities from the start. New `App\Livewire\Storefront\{SearchResults,CmsPage,BlogPost,WishlistPage,ComparePage,ReviewsSection,MessagingInbox,ConversationThread,GiftRegistryPage}`, each theme-resolved via the existing `ResolveStorefrontThemeMiddleware` — zero bypass of `ThemeRegistry`/`ThemeResolver`. Control Center: new nav entries via the existing `NavigationRegistryInterface::register()` (no registry changes) for Reviews/Q&A moderation, Messaging moderation, CMS (Pages/Blog/FAQ/Menus/Banners/Redirects), SEO configuration, Search configuration (synonyms/merchandising/analytics), Page Builder editor — same shell, same `<x-ui.*>` library, no separate admin app.

## 11. Messaging / Reverb Model

Persistence-before-broadcast is a hard, tested invariant: `MessagingService::send()` commits the DB transaction (`messages`, `message_attachments`, `conversations.last_message_at`) before `Modules\Messaging\Events\MessageSent implements ShouldBroadcast` is dispatched via `DB::afterCommit()`. `routes/channels.php` channel-auth callback and Livewire authorization both call the same `ConversationPolicy` — never duplicated logic. Attachments: private disk, signed temporary URLs only, MIME validated via real content inspection. Full schema/authorization model: plan §13.

## 12. Security

Cross-tenant leakage prevented by `BelongsToTenant` on every new table plus architecture tests. IDOR prevented by ownership-scoped lookups on every customer-owned resource (never a raw route-bound `find()`). CMS/review content sanitized via `stevebauman/purify` on write. Message attachments never served via predictable public paths. Rate limits on message-send/review-submit/Q&A-post. Reserved-slug validation extended from the existing vendor-slug pattern into a single shared `config('platform.reserved_slugs')` list consumed by both validators. Open redirects and redirect loops structurally prevented (bounded chain-walk at write time). Search: belt-and-suspenders isolation — unpublished/draft/archived content never indexed at all, and `SearchServiceInterface` force-injects caller tenant/store/channel context into every query. Full model: plan §36.

## 13. Package Integrity / Non-Financial Idempotency

Follow/wishlist/alert-subscribe use DB unique constraints + upsert, never check-then-insert. Alert notification dedup uses an atomic conditional `UPDATE ... WHERE notified_at IS NULL RETURNING *` — lighter than the Checkout/Payment `*OperationKey` pattern, which remains reserved for financial/checkout-grade replay safety and is not duplicated here for non-financial engagement mutations.

## 14. Tests

Comprehensive Pest coverage per domain (full list: plan §39), at minimum: wishlist/compare/recently-viewed/follow/alert uniqueness and dedup; `VerifiedPurchaseResolver` product+vendor paths including the `SellerOrder`-present/absent branches; messaging participant authorization + persistence-before-broadcast ordering (asserted, not assumed) + attachment access control; CMS tenant scoping/localized-fallback/slug-uniqueness/sanitization/redirect-loop-rejection; Page Builder block-schema validation + plugin block-type disable-degrades-safely + the `Blade::render()`-on-user-input forbidden-shortcut regression test; SEO no-leak-of-unpublished-content proof; search tenant/store isolation + merchandising-cannot-bypass-eligibility. Real-PostgreSQL constraint tests following the established `PostgreSql*Test` skip-if-unavailable pattern. Full regression of Phases 01-16 (859-test baseline) stays green throughout.

## 15. Documentation

`docs/modules/{CUSTOMERS,REVIEWS,MESSAGING,CMS,SEO,SEARCH,NOTIFICATIONS}.md` (matching existing per-module doc convention). `docs/DEPENDENCIES.md` updated (done — §6). Skills updated only where Phase-17 creates real runtime conventions: `realtime-messaging`, `seo-commerce`, `security-hardening`, `testing-quality`, `theme-sdk`, `plugin-sdk`. `PROJECT_MASTER_PLAN.md` not modified.

## 16. Acceptance Criteria

- [ ] Every Master §18 capability classified A/B/C/D, none silently lost.
- [ ] No second `User`/authentication architecture — `CustomerProfile` is strictly additive.
- [ ] `VerifiedPurchaseResolver` is the sole path deriving verified-purchase status from real Order data.
- [ ] Messaging persistence strictly precedes broadcast in every code path (architecture-tested).
- [ ] `SearchServiceInterface` is the one authoritative search contract; no parallel search paths; no cross-tenant/draft leakage (tested).
- [ ] No temporary `ILIKE` storefront search reintroduced.
- [ ] No hardcoded two-language schema anywhere new.
- [ ] No fake Phase-18 Market implementation.
- [ ] No open redirects; no redirect loops possible.
- [ ] Page Builder cannot execute arbitrary code from block content; no second Theme system; Phase-16 Plugin SDK lifecycle/security rules unmodified.
- [ ] `<x-ui.*>`-only UI, RTL/LTR-correct, no React/Vue/Next.
- [ ] Full Phase 01-16 regression stays green; PHPStan Level 8 clean; Pint clean; `npm run build`, `composer audit`, `npm audit --audit-level=high` clean.
- [ ] `docs/DEPENDENCIES.md` updated for every new package; `PROJECT_MASTER_PLAN.md` untouched.

## 17. Stop Condition

On completion of all acceptance criteria: run full validation suite, document results, produce the final acceptance report, commit and push, then **stop**. Do not begin Phase-18.
