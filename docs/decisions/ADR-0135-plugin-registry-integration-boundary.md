# ADR-0135: Plugin Registry Integration Boundary

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0135                              |
| Status      | Accepted                              |
| Date        | 2026-09-04                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-16                              |

## Context

A source audit prior to this phase confirmed exactly six real, multi-entry, reusable extension registries exist: `ProductTypeRegistry` (Catalog), `PaymentGatewayRegistry` (Payment), `CarrierRegistry` and `ShippingMethodTypeRegistry` (Shipping), `NavigationRegistry` and `ThemeRegistry` (Core, Phase-15, explicitly built Plugin-ready per ADR-0130/ADR-0131). The same audit confirmed Tax, Supplier-connector, and Fulfillment-packing capabilities are single 1:1 container bindings today, not registries — and that an existing accepted ADR (ADR-0065) commits to a `PackingStrategyRegistry` that was never actually built.

## Decision

Plugins integrate with all six existing registries by calling their exact existing public `register()` methods from `PluginServiceProvider::register()`/`boot()` — identical call shape to `CatalogServiceProvider`/`PaymentServiceProvider`/`ShippingServiceProvider` today. **Zero modification is made to any of the six registries' internal implementation.** No ownership/ProvenanceField is added to any of them — the per-request rebuild invariant (ADR-0133) makes that unnecessary for the "disabled plugin contributes nothing" guarantee.

Tax-provider, Supplier-connector, and Fulfillment-packing-strategy registries are **not built** in this phase. Building `PackingStrategyRegistry` to close ADR-0065's gap would be Fulfillment-module backend completion work, not Plugin SDK work, and is explicitly left as a documented, separately-justifiable future item rather than folded into this phase under cover of "it's small."

Plugin-contributed routes are structurally confined to `control-center/plugin/{plugin-id}/...` (Control Center admin screens) and `api/plugin/{plugin-id}/...` (Sanctum-gated API), mirroring the existing `control-center/<domain>` convention every module already follows. No storefront/public-facing plugin route capability is built — ADR-0006's isolation table restricts Plugins to "Admin UI extension points," and expanding that boundary would be a Class C architectural change requiring its own RFC.

Theme integration is limited to the smallest seam already fully supported: a plugin may register an entirely new theme via `ThemeRegistryInterface::register()`, or a new Product Type storefront section via the existing `ProductTypeInterface::getStorefrontTemplate()` seam. No hook/slot/block injection point into an *existing installed* theme is built — none exists in source today, and Page Builder remains explicitly out of scope.

## Consequences

- An architecture test asserts each of the six registry classes has exactly one implementation in the codebase — no parallel Plugin-specific registry is ever introduced.
- Plugin authors extending Product Types, Payment, or Shipping get a fully proven, already-tested integration path from day one.
- Plugin authors wanting to extend Tax, Supplier routing, or Fulfillment packing today cannot — this is an honest, documented limitation, not a silent gap.

## References

- ADR-0065 (Provider and Method Extension Boundaries for Future Plugins — Shipping)
- ADR-0130, ADR-0131 (Theme SDK, Navigation Registry — Plugin-ready seams)
- `docs/phases/PHASE-16-PLUGIN-SDK-EXTENSIBILITY-PLATFORM.md`
