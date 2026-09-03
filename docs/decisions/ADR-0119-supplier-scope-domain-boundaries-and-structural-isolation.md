# ADR-0119: Supplier Scope Domain Boundaries and Structural Isolation

## Status
Accepted

## Context
HyperStore supports multi-tenant operations with three distinct supplier scopes:
1. `platform`: Global supplier catalogs provisioned by platform administrators and made available to tenants via explicit access grants.
2. `tenant`: Suppliers private to a specific tenant.
3. `private_vendor`: Suppliers private to a specific marketplace vendor within a tenant.

Without strict structural boundaries and database-level invariants, a tenant or vendor might inadvertently access another party's supplier credentials, purchase orders, or catalogs. Furthermore, deactivating a supplier or revoking a tenant's access must immediately block new purchase orders and fulfillments without race conditions.

## Decision
1. **Database Schema & Check Constraints**:
   - `suppliers.scope` is restricted to `platform`, `tenant`, or `private_vendor`.
   - Composite foreign keys enforce tenant isolation across `supplier_locations`, `supplier_accounts`, `supplier_product_variants`, `supplier_offers`, and `purchase_orders`.
   - PostgreSQL constraint triggers (`check_purchase_order_supplier_scope`, `check_fulfillment_supplier_scope`, `check_spv_supplier_catalog_tenancy`) enforce cross-table tenancy rules at the database engine level.

2. **Credential Security**:
   - `supplier_accounts.credentials` is encrypted at rest using Laravel's `encrypted:array` cast.
   - Supplier accounts cannot be exposed via public or tenant APIs without appropriate administrative privileges.

3. **Concurrency Serialization**:
   - Fulfillment creation and Purchase Order materialization acquire row-level locks (`SELECT FOR UPDATE`) on the `Supplier` row.
   - For platform suppliers, `TenantSupplierAccess` is locked and asserted to have `is_enabled = true`.
   - If a supplier is deactivated or disabled concurrently, procurement fails closed before external dispatch.

## Consequences
- **Positive**: Zero risk of cross-tenant or cross-vendor supplier credential leakage; database-enforced structural invariants prevent corrupted procurement states.
- **Negative**: Pushes authorization and scope checks into database constraint triggers which requires PostgreSQL in production.
