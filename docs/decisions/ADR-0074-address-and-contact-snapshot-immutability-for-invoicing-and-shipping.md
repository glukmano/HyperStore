# ADR-0074: Address and Contact Snapshot Immutability for Invoicing and Shipping

## Status
Accepted

## Context
Shipping and billing addresses must remain immutable once recorded on a checkout session so subsequent changes to a customer profile do not retroactively alter historic checkout/order records.

## Decision
1. **Value Objects & JSON Snapshots**:
   - Shipping and Billing addresses are stored as structured JSON snapshots using the typed `CheckoutAddress` value object (recipient, street lines, city, region, postal code, country code, phone).
   - Normalized country and postal codes are used to construct `ShippingDestination` DTOs for Shipping and Tax calculation.
2. **Customer Contact Snapshot**:
   - `CheckoutCustomerData` stores email, name, phone, and optional VAT/Tax ID.

## Consequences
- Permanent, tamper-proof audit trail for billing and shipping destinations.
- Decoupling from mutable customer address book tables.
