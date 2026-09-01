# ADR-0069: CartLine Merge Identity and Normalized Signature Generation

## Status
Accepted

## Context
When a user adds an item to a cart that already contains the same product/variant, the system must decide whether to increment the existing line quantity or create a distinct line item (e.g. for custom engravings, personalized options, or distinct fulfillment choices).

## Decision
1. **Normalized Line Signature**:
   - Every `CartLine` computes a deterministic SHA-256 hash `signature`:
     `sha256(productId + ':' + (variantId ?? '0') + ':' + json_encode(sortedOptions) + ':' + json_encode(sortedCustomizations))`
2. **Merge Behavior**:
   - If a new item addition matches an existing line's signature, its quantity is incremented atomically.
   - If options or customizations differ, a distinct `CartLine` is created.
3. **Database Integrity**:
   - Enforced with a unique composite index `(cart_id, signature)` on the `cart_lines` table.

## Consequences
- Deterministic line item consolidation.
- Perfect preservation of product customizations and options.
