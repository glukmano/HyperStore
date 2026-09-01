# ADR-0076: Comprehensive Shipping Quote Fingerprint and Freshness Invalidation

## Status
Accepted

## Context
Selected shipping quotes must remain valid only while all rate-relevant parameters remain unchanged. Using an incomplete fingerprint risks applying a stale rate when the cart, package, or destination changes.

## Decision
1. **Comprehensive Normalized Fingerprint**:
   - `quote_fingerprint` is computed over a normalized canonical JSON object containing:
     `tenant_id`, `store_id`, `market_id`, `channel_id`, `currency`, `destination` (normalized), `method_id`, `method_code`, `carrier_code`, `service_code`, `fulfillment_allocations` (sorted), `packages` (sorted), `physical_lines` (sorted), `promotion_benefits`, `original_amount`, `final_amount`, `breakdown`, `provider_version`.
2. **Deterministic Invalidation**:
   - Any mutation to cart items, physical quantities, destination, or source allocations alters the fingerprint, instantly invalidating the selected quote and mandating a re-quote.

## Consequences
- Guaranteed shipping quote accuracy.
- Complete protection against applying stale or undercharged carrier rates.
