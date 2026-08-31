---
name: affiliate-marketing
description: Enforces first-party Affiliate program, referral tracking, attribution models, campaigns, and fraud detection. Use when developing affiliate, referral, promotion, or attribution features.
---

# Affiliate Program & Marketing Attribution

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 15, 16)

## Core Rules & Mandates

1. **First-Party Affiliate Architecture**:
   - Built-in affiliate profiles, custom referral codes, slug tracking, and shortlinks.
   - Affiliates can promote platform, tenant stores, specific vendors, categories, products, or campaigns.
2. **Attribution Engine & Strategies**:
   - Configurable attribution windows (e.g. 30 days, 60 days).
   - Support multiple attribution strategies: First-Click, Last-Click, Coupon-Assigned, and Multi-Touch/Manual.
3. **Financial Ledger Integration**:
   - Affiliate commissions generate immutable ledger movements upon order completion or return window expiry.
   - Payouts follow ledger balances and minimum payout thresholds.
4. **Fraud Detection & Abuse Prevention**:
   - Track IP, user-agent, device fingerprint, conversion speed, self-referral checks, and return rate anomalies.
   - Flag suspicious referral patterns for manual review.

## Pre-Execution Checklist
- [ ] Are referral cookies/sessions cryptographically signed and expiry-bounded?
- [ ] Are self-referral orders blocked or flagged by business policy?
- [ ] Are commission clawbacks triggered upon order refund/return?

## Forbidden Shortcuts
- ❌ Direct modification of affiliate balances without ledger entries.
- ❌ Hardcoding a single attribution strategy across all campaigns.
- ❌ Allowing unvalidated self-referrals.

## Validation Steps
1. Test click tracking, session resolution, and cookie attribution.
2. Test commission credit upon order completion and clawback upon refund.
3. Verify fraud detection signal triggers.
