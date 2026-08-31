# ADR-0044: Backorder and Preorder Inventory Policies

## Status
Accepted

## Context
Merchants need fine-grained control over whether customers can purchase out-of-stock items (backorders) or pre-release items (preorders).

## Decision
1. Backorder Policy Modes:
   - `deny`: Hard stop when ATS <= 0.
   - `allow`: Unlimited backorders allowed.
   - `allow_with_limit`: Backorders allowed up to `backorder_limit`.
2. Backorder Precedence Hierarchy:
   - `stock_items` override (Product/Variant at Source)
   - Store override
   - Source override
   - Tenant default
3. Preorder Hooks:
   - Supports `expected_availability_date` and `incoming_quantity` hooks integrated with Catalog's Preorder product type.

## Consequences
- Deterministic backorder evaluation enforced at the reservation boundary.
