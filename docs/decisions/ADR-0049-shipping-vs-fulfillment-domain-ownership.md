# ADR-0049: Shipping vs Fulfillment Domain Ownership and Module Boundaries

## Status
Accepted

## Context
E-commerce operations involve both calculating logistics costs/methods for delivery (Shipping) and deciding which physical or virtual sources can supply the items (Fulfillment). In monolithic designs, these are often conflated into an ambiguous "Shipping" model.

## Decision
1. Separate into two distinct modules: `modules/Shipping/` and `modules/Fulfillment/`.
2. `Shipping` owns zones, methods, carrier provider adapters, rate calculation rules, package constraints, delivery estimates, and local pickup/delivery configurations.
3. `Fulfillment` owns source allocation, fulfillment eligibility, split fulfillment planning, and packing strategies.
4. `Inventory` remains the sole source of truth for stock balances, reservations, and warehouses. Neither Shipping nor Fulfillment mutates inventory balances during rate quoting or planning.

## Consequences
- Clean separation of concerns with unidirectional dependency flow: Fulfillment -> Inventory & Shipping & Catalog; Shipping -> Platform & Pricing & Catalog.
