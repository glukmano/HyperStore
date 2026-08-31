---
name: seo-commerce
description: Enforces first-party SEO, JSON-LD structured data, canonical URLs, XML sitemaps, Open Graph, hreflang, and multi-store/vendor domain SEO. Use when developing storefront pages, metadata, URLs, or search engine indexing features.
---

# E-Commerce SEO, Structured Data & Metadata

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 11, 18, 19)

## Core Rules & Mandates

1. **First-Party SEO Architecture**:
   - SEO is a native platform feature, not a patchwork of third-party plugins.
   - Support friendly URLs, custom slugs, meta titles, descriptions, Open Graph, Twitter Cards, and canonical tags across all public pages.
2. **Rich Structured Data (Schema.org / JSON-LD)**:
   - Automatically inject valid JSON-LD schemas:
     - `Product` (name, image, description, sku, brand, offers, price, priceCurrency, availability, aggregateRating, reviews, return policy).
     - `BreadcrumbList` for navigation hierarchy.
     - `Organization` / `Store` for platform and vendor profiles.
     - `MerchantReturnPolicy` and `ShippingDetails`.
3. **Multi-Store & Vendor Domain SEO**:
   - Handle path-based (`/{vendor-slug}`), subdomain (`{vendor-slug}.example.com`), and custom domains (`vendor-domain.example`) seamlessly.
   - Enforce correct canonical tags and 301 redirects to avoid duplicate content penalties.
4. **Multilingual SEO & Sitemaps**:
   - Generate `hreflang` tags across all supported languages and market domains.
   - Generate dynamic, chunked XML sitemaps for products, categories, pages, and vendor storefronts with `lastmod` and `image:image` tags.

## Pre-Execution Checklist
- [ ] Are JSON-LD schemas valid according to Schema.org standards?
- [ ] Are canonical tags accurately referencing the primary canonical URL?
- [ ] Are hreflang alternate links provided for all active locales?

## Forbidden Shortcuts
- ❌ Duplicate canonical URLs pointing to paginated or filtered query strings.
- ❌ Missing image alt text or missing structured data on product pages.
- ❌ Uncached dynamic sitemap generation causing database exhaustion.

## Validation Steps
1. Validate JSON-LD output against Google Rich Results schema definitions.
2. Verify XML sitemap generation and valid XML syntax.
3. Test canonical tag behavior under query parameter filtering and custom domains.
