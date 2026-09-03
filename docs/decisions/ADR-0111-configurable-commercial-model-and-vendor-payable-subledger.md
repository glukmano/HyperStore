# ADR-0111: Configurable Commercial Model & Vendor Payable Subledger

## Status
ACCEPTED

## Date
2026-09-03

## Context
Marketplaces operate under different commercial models (Platform as Merchant of Record, Seller as Merchant of Record, Marketplace Agent). Assuming Platform-MoR universally would post inappropriate liabilities when the platform does not economically owe funds to the vendor.

## Decision
1. **Configurable Commercial Model**: Scoped via Store override (`Store.settings['marketplace']['commercial_model']`) with fallback to Tenant default (`Tenant.settings['marketplace']['commercial_model']`). Missing configuration fails closed.
2. **Policy-Gated Subledger Accrual**: `vendor_payable_entries` are created ONLY IF `MarketplaceCommercialPolicy::doesPlatformOweVendorPayable() === true`. In Seller-MoR mode where settlement is gateway-direct, no platform payable entry is created.
3. **Directional Subledger Polarity**: All monetary amounts are stored as positive integer minor units. Polarity is governed by typed `VendorPayableEntryType`:
   - Credit-like: `earning`, `manual_adjustment_credit`.
   - Debit-like: `refund_adjustment`, `manual_adjustment_debit`, `payout_disbursement`.
4. **General Ledger Seam**: General Ledger posting is a policy-driven extension (e.g. for Platform-MoR) and is not hard-coded into Ledger core.

## Consequences
- Platform-MoR, Seller-MoR, and Agent modes are cleanly supported without schema redesign.
- Subledger entries are directional, append-only, and economically consistent.
