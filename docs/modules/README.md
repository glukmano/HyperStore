# Modules Documentation

This directory contains architectural specifications, domain contracts, event definitions, and public API interfaces for each first-party module in the **Hyper Commerce Platform**.

## Modular Monolith Structure

All business features reside in `modules/` with strict internal encapsulation:

```text
modules/
├── Catalog/
├── ProductTypes/
├── Pricing/
├── Inventory/
├── Warehouses/
├── Cart/
├── Checkout/
├── Orders/
├── Marketplace/
├── Vendors/
├── Ledger/
├── Wallet/
├── Payouts/
├── Fulfillment/
├── Dropshipping/
├── PrintOnDemand/
├── Payments/
├── Shipping/
├── Taxes/
├── Customers/
├── Reviews/
├── Messaging/
├── Support/
├── Affiliate/
├── Referral/
├── Loyalty/
├── Promotions/
├── Search/
├── Seo/
├── Cms/
├── B2B/
├── Auctions/
├── Booking/
├── Subscriptions/
├── DigitalDelivery/
├── GiftCards/
├── Pos/
├── Notifications/
├── Analytics/
├── Localization/
├── Markets/
└── Licensing/
```

## Module Documentation Guidelines

Each module folder in this documentation directory (`docs/modules/<ModuleName>/`) must include:
1. `README.md`: Overview, responsibility, and dependencies.
2. `CONTRACTS.md`: Public services, interfaces, DTOs, and events emitted/listened to.
3. `DATABASE.md`: Schema, indexes, and transactional boundaries.
4. `SECURITY.md`: Permissions, tenant scoping, and authorization policies.
