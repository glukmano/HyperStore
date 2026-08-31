# ADR-0019: Catalog Multi-Language Localization Strategy

## Status
Accepted

## Context
HyperStore is built for global commerce with first-class RTL and LTR support. Hardcoding columns such as `name_en` and `name_ar` limits the platform to arbitrary languages and violates scalability requirements.

## Decision
1. Adopt normalized translation tables for all user-facing catalog entities:
   - `brand_translations (brand_id, locale, name, description, slug)`
   - `category_translations (category_id, locale, name, description, slug)`
   - `attribute_translations (attribute_id, locale, name, description, unit_label)`
   - `attribute_option_translations (attribute_option_id, locale, label)`
   - `product_translations (product_id, locale, name, short_description, description)`
   - `product_store_listing_translations (listing_id, locale, slug, name, description)`
   - `product_custom_field_translations (custom_field_id, locale, label, help_text, placeholder)`
   - `product_custom_field_option_translations (option_id, locale, label)`
2. Eloquent models use translation helper traits with automatic fallback to tenant default locale or application default (`en`).
3. Core technical identifiers (`code`, `sku`, `type`, `status`) remain untranslated on parent tables.

## Consequences
- Unlimited language support without database schema alterations.
- Native RTL/LTR translation resolution.
- Reliable fallback mechanism when localized strings are missing.
