# ADR-0123: Vendor Warehouse Ownership and Suspension Gating

## Status
Accepted

## Context
Master Plan Section 14 explicitly requires "Vendor warehouse" support — not optional. No `vendor_id` or ownership concept exists on `Warehouse` today (see ADR-0122). Phase-11 already established a proven, repeated pattern for gating mutating operations on Vendor operational status: lock `Vendor` `FOR UPDATE`, check `operational_status !== VendorOperationalStatus::Active`, throw `VendorOperationalStatusException::vendorNotActive(...)` — used identically in `VendorListingCreationService`, `VendorPayableSubledgerService`, and `PayoutService`. Phase-13 established the exact composite-FK syntax for tenant-scoped Vendor references: `suppliers.vendor_id` uses `foreign(['tenant_id','vendor_id'])->references(['tenant_id','id'])->on('vendors')`.

## Decision
1. Add `warehouses.vendor_id` (nullable), with composite FK `(tenant_id, vendor_id) → vendors(tenant_id, id)`, populated only when `ownership_type='vendor'` (app-level `saving()` guard, matching Inventory's existing tenant-consistency convention).
2. **Deliberate deviation** from the `suppliers.vendor_id` precedent's `cascadeOnDelete()`: use `restrictOnDelete()` — a Warehouse can carry an immutable `InventoryMovement` ledger that must never be cascade-deleted by a Vendor row deletion.
3. Reuse the exact existing `VendorOperationalStatusException::vendorNotActive()` pattern before any **new** mutating Inventory operation (transfer create/dispatch, adjustment) against a source under a vendor-owned warehouse. Historical stock/movement visibility is never gated — `InventoryMovement` is already immutable, so history cannot be rewritten regardless of current Vendor status.
4. Cross-vendor access denial: the acting Vendor context (Phase-12 `ContextManager`) must match `warehouses.vendor_id` before any mutating call — Vendor A can never operate Vendor B's warehouse.
5. Tenant/Super Admin recovery access reuses the existing `$user->can(...) || $user->is_super_admin` bypass pattern used throughout the codebase — no new mechanism.

## Consequences
- **Positive**: Vendor-warehouse ownership is structurally enforced (composite FK) and behaviorally consistent with every other Vendor-gated mutation in the codebase; historical data integrity is preserved regardless of Vendor lifecycle changes.
- **Negative**: none identified — purely additive.
