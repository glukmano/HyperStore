# ADR-0118: Master Order / Seller Order Hierarchy and Line Allocation

## Status
Accepted

## Context
In a multi-vendor marketplace, a single customer Master Order may contain items from multiple independent sellers (platform inventory as well as third-party marketplace vendors). Each seller must fulfill their own partition of the order, receive isolated order views, and be credited with precise, deterministic financial allocations.

The financial breakdown of a Master Order includes merchandise subtotals, item discounts, allocated cart-level discounts, line taxes, and joint shipping charges. Under multi-vendor conditions, joint shipping totals and discounts must be partitioned across participating sellers without rounding drift, floating-point inaccuracies, or financial leakages.

Furthermore, commercial models (`platform_as_merchant_of_record`, `seller_as_merchant_of_record`, `marketplace_agent`) and shipping eligibility cannot be resolved dynamically at split time from live store settings or runtime product registries; historical order snapshots must remain completely immutable.

## Decision
1. **Historical Immutability at Checkout Ready State**:
   - `CheckoutReadyResult` freezes `commercial_model_snapshot` inside its `context` and `requires_shipping_snapshot` (boolean) on each line.
   - `OrderCreationService` copies these values verbatim into `orders.commercial_model_snapshot` and `order_items.requires_shipping_snapshot`.
   - `MasterOrderSplitService` fails closed if `commercial_model_snapshot` is missing or if `shipping_total_minor > 0` and any item lacks `requires_shipping_snapshot`.

2. **Idempotent Partitioning & Partial Unique Constraints**:
   - `SellerOrder` records are created within a database transaction.
   - Partitioning groups `OrderItem`s by seller identity: `vendor_id IS NULL` for platform partitions, and `vendor_id` for vendor partitions.
   - Idempotency is enforced by returning existing records if already split, and concurrency races are caught gracefully by partial unique indexes.

3. **Joint Shipping Allocation via Largest Remainder**:
   - Shipping is allocated strictly across partitions containing shipping-eligible items (`requires_shipping_snapshot = true`).
   - `JointShippingAllocationService` applies the largest remainder method (`brick/math` arbitrary-precision arithmetic) to allocate both final shipping and shipping discounts.
   - Original shipping is derived as `final + discount`, guaranteeing complete mathematical conservation:
     `sum(seller_orders.shipping_final) == master_order.shipping_total_minor`
     `sum(seller_orders.total) == master_order.grand_total_minor`.

## Consequences
- **Positive**: Strict financial conservation without penny rounding drift; immutable historical commercial models protected against post-order configuration mutations; clean IDOR-safe isolation between vendor and platform orders.
- **Negative**: Requires historical snapshot fields to be populated at checkout ready finalization; legacy orders with missing shipping snapshots cannot be split if shipping is greater than zero.
