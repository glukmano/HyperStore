# ADR-0140: Domain/Market Hostname Resolution Model

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0140                              |
| Status      | Accepted                              |
| Date        | 2026-09-05                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-18                              |

## Context

Three independent hostname-resolution stacks existed pre-Phase-18: `App\Core\Stores\Models\StoreDomain` + `DomainAddressingService` (Store/Tenant), `Modules\Marketplace`'s `vendor_domains` + `VendorStorefrontResolver` (Vendor storefronts), and ad-hoc header/route-param Tenant resolution. Each table enforced its own `UNIQUE(domain)` independently — which does not stop `shop.example.com` being claimed once as a Store domain and once as a Market domain (or a Vendor domain), because the three uniqueness universes never talk to each other. Separately, a Market attached to more than one Store (a real, intended shape — `market_countries`/`market_currencies`/`market_languages` are all many-to-many by design) meant a hostname mapped only to `market_id` could not unambiguously identify which Store to serve.

## Decision

**One global hostname claim registry.** A new `hostname_claims(normalized_hostname UNIQUE, owner_type, owner_id)` table is the sole arbiter of "does this exact host already belong to someone" — `StoreDomain`, `VendorDomain`, and the new `MarketDomain` models each fire a claim through `App\Core\Routing\HostnameClaimService::claim()` inside their `creating` event, backed by the table's real DB unique constraint (not a check-then-write race), releasing the claim on delete. Each table keeps its own existing per-table `UNIQUE(domain)` as a second line of defense; `hostname_claims` is what actually prevents cross-table collision.

**A regional (Market) domain identifies a Store+Market pair, never a Market alone.** The new `market_domains` table references `store_markets.id` directly (`store_market_id`), not `market_id` — resolving one exact hostname always yields an unambiguous Store+Market, even when that Market is attached to several Stores. `App\Core\Routing\DomainAddressingService::resolveHostContext()` returns a `ResolvedHostContext(store, market)` DTO, checking `market_domains` (verified only) first, then falling back to the existing `store_domains`/subdomain-heuristic path.

**Hostnames are normalized once, consistently.** `App\Core\Routing\HostnameNormalizer::normalize()` (lowercase, no scheme/path/port, no trailing dot) is used by every write path and by the read path, so the same host string always maps to the same claim key. New domains of every kind default to `is_verified = false`; nothing resolves storefront traffic until an operator explicitly verifies it. A partial unique index (`market_domains_one_canonical_per_context`) guarantees at most one canonical domain per Store+Market pair.

The pre-existing Vendor-domain stack (`VendorStorefrontResolver`) is deliberately **not** merged into `DomainAddressingService` — it remains its own bounded context (Marketplace-owned, self-service verification flow) that now also participates in the shared `hostname_claims` registry, rather than being absorbed into a Core-owned resolution path it doesn't otherwise need.

## Consequences

- A hostname can never be claimed by two of Store/Market/Vendor at once — proven by real PostgreSQL tests exercising all three pairwise collisions.
- Domain-per-locale/Market storefront patterns (`de.example.com`, `example.de`, a bare custom domain) are all expressible as ordinary `market_domains`/`store_domains` rows — no new pattern-matching engine.
- No SaaS billing/entitlement/self-service verification logic is introduced for Market domains — `is_verified` is an operator-set boolean, matching the platform's current non-multi-tenant-SaaS scope.

## References

- `docs/phases/PHASE-18-INTERNATIONALIZATION-MARKETS-REGIONALIZATION-RTL.md`
- `app/Core/Routing/{HostnameClaimService,HostnameNormalizer,DomainAddressingService}.php`, `app/Core/Markets/Models/MarketDomain.php`
