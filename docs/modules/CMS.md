# CMS Module Specification

**Module Namespace**: `Modules\Cms`
**Root Path**: `modules/Cms/`
**Status**: Active Production Module (PHASE-17)

---

## 1. Overview

Owns Pages, Blog, FAQ, Menus, Banners, Redirects, and a bounded Page Builder foundation (`ADR-0137`) — the Plugin SDK's 7th registry.

## 2. Localization

Every content type follows the established `Modules\Catalog\Models\CategoryTranslation` per-locale-row pattern exactly: `page_translations`, `blog_post_translations`, `faq_translations`, `menu_item_translations`, `banner_translations`, each with a plain `locale` string column (no `title_ar`/`title_en` columns anywhere) and a locale-fallback accessor (`translation(?string $locale)`) on the parent.

## 3. Page Builder (ADR-0137)

`Modules\Cms\Contracts\BlockTypeRegistryInterface` mirrors the Phase-16 Plugin SDK's six existing registries' exact shape (`register()`/`has()`/`get()`/`all()`), rebuilt every request — preserving the per-request-rebuild disable invariant. Five first-party block types only: `rich_text`, `hero`, `image_gallery`, `product_grid`, `html` (the last gated behind `cms.page.use_html_block` permission). `Modules\Cms\Services\PageBlockRenderer` is server-rendered Blade only — `config` is schema-validated JSONB data passed into a fixed template, **never** `Blade::render()`'d from user input, and a block type whose provider (plugin) was disabled degrades to a safe `_unavailable` placeholder rather than erroring.

A plugin may register a block type via `BlockTypeRegistryInterface::register()` inside its own `PluginServiceProvider::boot()` — admin-side/boot-time code execution — while the block's actual rendering on storefront pages is always done by Core's `PageBlockRenderer`, never a plugin-owned route (no exception to ADR-0006).

## 4. Reserved Slugs

`Modules\Cms\Services\CmsSlugValidator` shares the exact same `App\Core\Support\ReservedSlugs::LIST` constant that `Modules\Marketplace\ValueObjects\VendorSlug` already enforced for vendor slugs — one list, not two independently-drifting ones — preventing a CMS page from ever claiming a route a future feature needs (e.g. `/search`).

## 5. Redirects

`Modules\Cms\Services\RedirectService`: targets must be relative platform paths unless explicitly flagged `is_external` (no open redirects). Loop/chain-depth prevention walks both the backward (incoming) and forward (outgoing) chain at write time, bounded to 5 total hops.

## 6. Sanitization

Rich-text/blog body content and the `html` block type are sanitized via `App\Core\Support\Contracts\ContentSanitizerInterface` (wrapping `stevebauman/purify`) on every write — never trusted as pre-sanitized at render time from an untrusted source.

## 7. Control Center Screens

All CRUD/moderation surfaces are wired (Phase-17 completion delta): `modules/Cms/Livewire/{PageManager,PageEditor,BlogManager,FaqManager,MenuManager,BannerManager,MediaLibraryManager,RedirectManager}.php`, each gated by `cms.view`/`cms.manage` the same inline `->can()` + `is_super_admin` pattern as every other Control Center screen. `PageEditor` supports real block CRUD — add (with a JSON config editor scoped to the registered block type), reorder (move up/down), and remove — not just a read-only block list. `MediaLibraryManager` is intentionally scoped to Banner-owned media only: Spatie MediaLibrary's `media` table has no `tenant_id` of its own, so a fully universal cross-module media browser is out of scope for this pass; tenant scoping here is via the owning Banner's `tenant_id`.

## 8. Tests

`tests/Feature/Cms/{PageBuilderTest,CmsContentTest}.php`, `tests/Feature/ControlCenter/Phase17ControlCenterScreensTest.php`, `tests/Feature/Storefront/Phase17StorefrontPagesTest.php`.
