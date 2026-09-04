# ADR-0137: Page Builder Block Type Registry and Rendering Model

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0137                              |
| Status      | Accepted                              |
| Date        | 2026-09-04                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-17                              |

## Context

`PROJECT_MASTER_PLAN.md` §18 requires "Page Builder supports reusable Theme-compatible blocks and plugin-provided blocks." Source audit confirmed `app/Core/Theme/` has zero block/slot/section registry today — a Theme is a name+version+optional-parent+resolved Blade view-path chain, with exactly one dynamic composition seam anywhere in the system (`themes/default/pages/product.blade.php`'s per-Product-Type `@include`). Master §22 anticipates Theme eventually supporting "sections... hooks," but nothing exists yet. CMS `Page` content (Master §18) needs a rendering mechanism, and Phase-16's Plugin SDK (`app/Core/Plugin/`) integrates with exactly six existing registries (Navigation, Theme, ProductType, PaymentGateway, Carrier, ShippingMethodType) — none of them a content/block registry. Plugins are structurally restricted to Control Center (admin) routes only (ADR-0006); Page Builder blocks render exclusively on storefront pages — a naive reading suggests a plugin could never contribute a block, which is not what Master intends.

## Decision

**Build a bounded Page Builder foundation now**, not defer it. The zero-block-registry state is a greenfield gap, not a conflict with existing design — nothing in Theme's current shape prevents adding a first block/slot concept, and building CMS pages as raw-HTML-only now and retrofitting blocks later would cost strictly more than building the minimal version first.

**Schema**: `Modules\Cms\Models\Page` has-many ordered `page_blocks` (`page_id, block_type, position, config jsonb, is_visible, timestamps`). `config` is genuinely dynamic per-block-type data — the one legitimate JSONB use here, per Master §26's "JSONB only for genuinely dynamic metadata" rule.

**One authoritative registry**: `Modules\Cms\Contracts\BlockTypeRegistryInterface::register(BlockTypeDefinition $definition): void`, mirroring the exact shape of the six existing Plugin SDK registries — a plain in-memory `register()`/`all()`/`get()` contract, bound as a singleton and rebuilt every request in `AppServiceProvider::boot()`, preserving the established per-request-rebuild disable invariant (ADR-0133): a plugin that stops registering a block type on the next request cycle simply stops offering it for new placement, with zero ownership-tracking code added anywhere.

**Five first-party block types only**: `RichText`, `Hero`, `ImageGallery`, `ProductGrid` (reads Catalog via its public contracts, never queries Catalog tables directly), `Html` (sanitized, gated behind a dedicated `cms.page.use_html_block` permission). Columns/grid-layout, form, embed/iframe, and custom-code block types are explicitly deferred — named, not silently dropped.

**Rendering is server-rendered Blade only**: `PageBlockRenderer::render(PageBlock $block): View` resolves `block_type` against the registry's `viewPath`, passing schema-validated `config` into a plain Blade `@include`. **Hard forbidden shortcut**: `config` is JSONB data, never PHP-`eval`'d and never compiled as a runtime Blade string from user input (`Blade::render()` on user input is disallowed outright, in any block type including `Html`).

**Plugin seam — the ADR-0006 tension resolved explicitly**: a plugin may register a block type (definition + Blade view + config schema) via `BlockTypeRegistryInterface::register()` inside its own `PluginServiceProvider::boot()`. Registration is admin-side, boot-time code execution in the same PHP process as Core, regardless of which route eventually serves the resulting page — this is structurally identical to how a plugin already registers a `ProductType` or `ShippingMethodType` whose effects are felt on storefront/checkout pages without the plugin ever owning a storefront route. The plugin's Blade view is rendered by **Core's** `PageBlockRenderer`, never by a plugin-owned route. This introduces the SDK's **seventh** registry, following the exact `register()`-in-`boot()` pattern established by the other six — **no exception to ADR-0006 is created**, and disabling the plugin makes the block type unavailable for new placement on the very next request while any already-placed `page_blocks` rows degrade to a safe "block unavailable" placeholder rather than erroring.

## Consequences

- CMS `Page` content has a real, extensible rendering mechanism from day one, avoiding a costly later migration off a raw-HTML-blob model.
- The Plugin SDK gains its first content-rendering registry without any change to Phase-16's lifecycle, security, or trust model.
- The `Html` block type is the one component requiring ongoing security review (sanitizer correctness); every other block type is inherently safe by construction (schema-validated JSONB into a fixed Blade template).
- Column/grid-layout and code-execution block types remain explicitly out of scope until a real second consumer justifies them — consistent with the platform's "no speculative extension registries" rule.

## References

- `docs/phases/PHASE-17-CUSTOMER-ENGAGEMENT-MESSAGING-CMS-SEO-SEARCH.md` §17-19
- ADR-0006 (Theme and Plugin Isolation)
- ADR-0133 (Plugin SDK Kernel and Identity — per-request rebuild invariant)
- ADR-0135 (Plugin Registry Integration Boundary)
