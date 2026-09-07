# Demo Dataset

`php artisan demo:seed` (run **after** the normal `php artisan db:seed`) creates a compact, coherent Demo Dataset for local exploration. It is intentionally separate from `db:seed` — normal seeding never creates demo commerce data.

## Safety

- Refuses to run in a production environment (`APP_ENV=production`) unless `--force-in-production` is explicitly passed.
- Idempotent — re-running produces no duplicate rows (verified in `tests/Feature/DemoDataset/DemoSeedCommandTest.php`).
- All demo users use the fixed local password `password` (bcrypt-hashed via the real `User` model cast) — **local/demo use only, never a real deployment.**

## What Gets Seeded

**Foundation**: Tenant (`demo`), Store (`Demo Flagship Store`), Website + POS Channels, Market (US/USD), Warehouse + InventorySource, PickupLocation, one POS Register, and one user per representative role.

| Role | Email |
|---|---|
| Store Owner | `owner@demo.hyperstore.test` |
| Cashier | `cashier@demo.hyperstore.test` |
| Customer | `customer@demo.hyperstore.test` |
| Vendor Staff | `vendor@demo.hyperstore.test` |
| B2B Buyer | `b2b-buyer@demo.hyperstore.test` |
| B2B Approver | `b2b-approver@demo.hyperstore.test` |
| Platform Super Admin | `admin@hyperstore.test` (seeded by `db:seed`) |

**Catalog**: 6 representative products — Physical, Physical+Variant, Digital (with a digital asset), License, Gift Card, Subscription-eligible — with categories, translations, prices, inventory, SKU, and barcode.

**Business data**: one completed Order (via the real Cart→Checkout→Payment pipeline), one Wallet balance, one Gift Card.

## Explicitly Deferred (not seeded this pass)

B2B Company/Quote workflow, an Auction, a Booking, a Subscription signup, an RMA/Return example, and POS register-session history. The foundation for these (B2B buyer/approver users, the POS Register itself) is already seeded so a future pass can extend `DemoBusinessDataSeeder` without redoing the foundation. See `docs/qa/LOCAL-PRODUCTION-READINESS.md` for the full readiness matrix.
