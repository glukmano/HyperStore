# ADR-0018: Strict Distinction Between Attributes, Variants, and Customer Inputs

## Status
Accepted

## Context
Catalog systems frequently confuse three distinct commercial concepts:
1. Product Specifications (e.g. Material = 100% Cotton)
2. Purchasable Dimension Combinations (e.g. Color = Blue, Size = XL -> SKU-TSHIRT-BLU-XL)
3. Buyer-Supplied Customization Inputs (e.g. Engraving Text = "Alexander")

Collapsing these into a single model creates ambiguity in inventory, cart serialization, and SKU tracking.

## Decision
1. **Attributes**: Represent static product specifications, comparisons, and faceted filtering. Defined in `attributes` and `product_attribute_values`.
2. **Variant Options**: Represent purchasable physical/digital matrix dimensions that create distinct `ProductVariant` records with unique SKUs. Defined in `product_variants` and `product_variant_options`.
3. **Customer Inputs**: Represent dynamic buyer inputs collected during checkout. Defined in `product_custom_fields` with selectable option sets in `product_custom_field_options`.

## Consequences
- Clear conceptual separation across the entire domain model.
- Variant generation algorithms operate strictly on dimension attributes.
- Custom fields do not pollute product variant combinatorics.
