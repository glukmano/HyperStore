# ADR-0113: Global Vendor Slug, Domain Verification & Storefront Resolver

## Status
ACCEPTED

## Date
2026-09-03

## Context
Section 11 of `PROJECT_MASTER_PLAN.md` supports three URL modes: path mode (`platform.example/{slug}`), subdomain mode (`{slug}.platform.example`), and custom domain mode (`vendor-domain.example`). Ambiguous routing arises if subdomains are tenant-scoped or if unverified custom domains can be claimed.

## Decision
1. **Globally Unique Platform Slug**: The canonical vendor platform slug is globally unique across the platform (`UNIQUE (platform_slug)`). Normalized to lowercase, alphanumeric and hyphens, 3–64 characters, with reserved words centrally rejected.
2. **Custom Domain Verification Lifecycle**: Custom domains undergo a strict lifecycle (`requested` $\to$ `verification_pending` $\to$ `verified` $\to$ `active`). Verification requires a DNS TXT challenge token. Unverified domains cannot be activated or routed.
3. **Normalized Host Uniqueness**: Canonical ASCII hostnames are globally unique across the platform (`UNIQUE (domain)`).
4. **Unified Resolver (`VendorStorefrontResolver`)**: A central domain service handles all 3 URL modes, enforcing vendor operational status, store context, verified custom domains, and canonical redirect semantics.

## Consequences
- Subdomain routing resolves unambiguously across the platform.
- Domain hijacking is prevented via ownership challenge verification.
