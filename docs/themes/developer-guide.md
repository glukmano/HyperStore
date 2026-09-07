# Theme Developer Handbook

This handbook documents the actual, implemented Theme SDK. Every field, class, and file path below was verified against source at `app/Core/Theme/` and `themes/default/`. Where a capability does not yet exist, this document says so explicitly rather than inventing an API.

## 1. Directory Structure

A theme is a directory under `themes/{theme-name}/`. The shipped `default` theme demonstrates every real convention:

```
themes/default/
  theme.json                                # manifest (required)
  layouts/app.blade.php                     # full-page skeleton
  pages/                                    # one Blade view per storefront route
    home.blade.php
    category.blade.php
    product.blade.php
    cart.blade.php
    checkout.blade.php
    cms-page.blade.php
    compare.blade.php
    search-results.blade.php
    order-confirmation.blade.php
    order-lookup.blade.php
    vendor-storefront.blade.php
    gift-registry-public.blade.php
    account/
      conversation-thread.blade.php
      gift-registries-index.blade.php
      gift-registry-editor.blade.php
      messages-index.blade.php
      notification-preferences.blade.php
      recently-viewed.blade.php
      referral-share.blade.php
      wishlist.blade.php
  components/                               # reusable <x-...> Blade components
    account-nav.blade.php
    cart-recommendations.blade.php
    product-card.blade.php
    product-qa-section.blade.php
    product-recommendations.blade.php
    product-reviews-section.blade.php
    regional-switcher.blade.php
    vendor-reviews-section.blade.php
  sections/product-types/                   # per-ProductType detail-page fragments
    default.blade.php                       # fallback for every ProductType not listed below
    auction.blade.php
    booking.blade.php
```

There is no separate "assets" pipeline abstraction — a theme's CSS/JS ships through the platform's existing Vite build (Tailwind CSS 4 + daisyUI 5), the same way the core storefront does. A theme does not bundle its own compiled assets separately today.

## 2. The Manifest (`theme.json`)

Parsed by `App\Core\Theme\DTOs\ThemeManifest::fromArray()` / `fromJsonFile()`. The **real, complete field set** — nothing more exists:

```json
{
    "name": "default",
    "version": "1.0.0",
    "extends": null,
    "description": "Built-in fallback storefront theme...",
    "supported_product_type_templates": ["auction", "booking"]
}
```

| Field | Required | Type | Meaning |
|---|---|---|---|
| `name` | Yes | string | The theme's directory name / identifier. |
| `version` | No (defaults `1.0.0`) | string | Informational — not currently enforced against a compatibility range. |
| `extends` | No (defaults `null`) | string\|null | Parent theme's `name`, for inheritance (see §4). |
| `description` | No | string | Informational only — **not parsed into the `ThemeManifest` DTO at all**, display-only if you choose to read the raw JSON yourself. |
| `supported_product_type_templates` | No (defaults `[]`) | array\<string\> | Declares which ProductType identifiers this theme ships a real `sections/product-types/{type}.blade.php` for. Must be kept honest — see §6. |

There is no `layouts`, `assets`, `authors`, or `license` field in the manifest today — do not invent one.

## 3. Activation — Store-Aware Theme Selection

Each `Store` has a real `active_theme` column (`stores.active_theme`, string, default `'default'` — migration `2026_09_04_000070_add_active_theme_to_stores_table.php`). It is changed in Control Center via `App\Livewire\ControlCenter\StoreManager::editTheme()`/`saveTheme()`, gated by the `stores.manage` permission.

Resolution for an incoming storefront request goes through `App\Core\Theme\ThemeResolver::resolveForStore(?Store $store)`, which falls back to `'default'` when a Store has no `active_theme` set (or no Store is in context at all).

## 4. Inheritance / Child Themes

Real and implemented — `ThemeResolver::resolveChain()` walks a theme's `extends` chain:

- Maximum depth: 5 (`MAX_INHERITANCE_DEPTH`).
- Cycle-safe — a theme that (directly or transitively) extends itself is detected and does not infinite-loop.
- A missing parent in the chain is handled gracefully.
- The chain **always** ends by appending `'default'` as the final fallback, deterministically.
- The result is a `ResolvedTheme` DTO: `activeThemeName`, `chain` (list, most-specific first), `viewPaths`.

In practice: if your theme `extends: "default"` and does not ship its own `pages/product.blade.php`, Laravel's view-path-cascade (built from `viewPaths`) falls through to `default`'s `pages/product.blade.php` automatically — you only need to override the specific views you actually want to change.

## 5. Layouts, Pages, Components

These are plain Blade conventions, not a custom templating abstraction:

- **`layouts/app.blade.php`** — the full HTML document skeleton (head, nav, footer) that every `pages/*.blade.php` renders inside of.
- **`pages/*.blade.php`** — one file per storefront route (home, category, product, cart, checkout, etc.). Override only the ones your theme needs to change.
- **`components/*.blade.php`** — reusable Blade components referenced as `<x-account-nav>`, `<x-product-card>`, etc. within your theme's own views. These are theme-scoped components, separate from the platform-wide `<x-ui.*>` library, which remains available and should be preferred for generic UI primitives.

## 6. ProductType Templates (`sections/product-types/`)

The platform's Catalog module supports many ProductTypes (Physical, Digital, Auction, Booking, Subscription, GiftCard, License, and more). A theme may ship a dedicated detail-page fragment per type at `sections/product-types/{type}.blade.php`. **Today, the shipped `default` theme provides exactly two**: `auction.blade.php` and `booking.blade.php` — every other ProductType falls through to `sections/product-types/default.blade.php`, a generic capability-driven template (title/price/description/variant-selector/add-to-cart) that renders correctly for any type without a dedicated fragment.

`theme.json`'s `supported_product_type_templates` array must accurately list only the types you actually ship a dedicated fragment for — the platform does not validate this against the filesystem today, so an inaccurate manifest is a real, silent risk you are responsible for avoiding.

## 7. Translations, Locale, RTL/LTR, Market Context

Themes reuse the platform's existing localization stack (Phase-18) — there is no theme-specific i18n mechanism:

- Locale-aware strings go through the same translation files the core storefront already uses; a theme does not ship a parallel translation system.
- RTL/LTR is handled via logical CSS properties (`ms-*`/`me-*`/`ps-*`/`pe-*`/`start-*`/`end-*` Tailwind utilities), never hardcoded `left`/`right`. Verify any new theme view in both an LTR (e.g. `en`) and RTL (`ar`) locale before shipping.
- Currency/Market context is resolved the same way for every theme — through `ContextManager`/`Market`/`MarketCurrency` — a theme never needs to (and must not) implement its own currency-formatting or market-detection logic.

## 8. Responsive Behavior & `<x-ui.*>` Reuse

Prefer the existing `<x-ui.*>` components for anything generic (buttons, tables, modals, alerts, pagination) — they already carry the platform's responsive/RTL-safe/daisyUI-consistent behavior. Reserve theme-local `components/` for genuinely storefront-presentation-specific pieces (a product card layout, a recommendation carousel) that don't belong in the shared admin-facing `<x-ui.*>` library.

## 9. Safe Overrides — What a Theme MUST NOT Do

- A theme **must not** modify domain/service-layer code, Livewire component *logic* classes, routes, or migrations — a theme is a presentation layer only (Blade views + theme-scoped components), never a code-execution extension. (Compare: that broader capability is exactly what the **Plugin SDK** exists for — see `docs/plugins/`.)
- A theme **must not** bypass Pricing/Tax/Inventory results to compute or display its own derived commercial numbers — always render what the domain layer already resolved.
- A theme **must not** hardcode a Store's Market/currency/locale — always render through the request's resolved context.

## 10. Version Compatibility & Update Strategy

There is no dedicated theme versioning/compatibility-enforcement service today (that is explicitly out of scope for this pre-production stage — see `PROJECT_MASTER_PLAN.md` and the Phase-23 boundary). A theme's `version` field is informational. Check the platform baseline documented in `docs/DEVELOPER-CENTER-VERSION.md` before building against a specific Laravel/Tailwind/daisyUI release, and re-test after any platform upgrade.

## 11. Security Boundaries

Themes render inside the normal Blade compilation pipeline — there is no sandboxing beyond what Blade itself provides. Because a theme is Blade-only (no PHP service classes of its own), its blast radius is inherently limited to presentation: it cannot register routes, listen to events, or touch the database directly. If your theme needs real backend behavior, build a **Plugin** instead (see `docs/plugins/`) and have your theme call into it through the plugin's own registered contracts.

## 12. Minimal Worked Example

To create a new theme that only re-skins the homepage:

```
themes/my-theme/
  theme.json
  pages/home.blade.php   # your custom homepage — everything else falls through to `default`
```

```json
{
    "name": "my-theme",
    "version": "1.0.0",
    "extends": "default",
    "description": "A homepage-only re-skin of the default theme.",
    "supported_product_type_templates": []
}
```

Then activate it for a Store via Control Center → Platform → Stores → (select Store) → Theme, or generate this same skeleton instantly with `php artisan theme:make my-theme`.
