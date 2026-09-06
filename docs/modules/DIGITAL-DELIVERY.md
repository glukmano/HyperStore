# Digital Delivery Module Specification

**Module Namespace**: `Modules\DigitalDelivery`
**Root Path**: `modules/DigitalDelivery/`
**Status**: Active Production Module (PHASE-21)

---

## 1. Overview & Architectural Boundaries

The `DigitalDelivery` module adds entitlement-gated secure file downloads and individually-distinct license-key allocation, integrating through the existing Catalog/Order/Payment pipeline. See `docs/decisions/ADR-0147-shared-digital-subscription-entitlement-model.md`.

### Key Invariants:

1. **One shared entitlement model**: `CustomerEntitlement` (`entitlement_type` = `digital_download` or `subscription_access`) is distinct from `OrderItem` — the current permission vs. the immutable historical purchase.
2. **Entitlements are granted only on payment success**: `GrantEntitlementsOnPaymentCaptured` listens to `PaymentCaptured`, never at Order-creation time itself.
3. **Atomic reserve-before-stream (Owner Delta §13)**: `DigitalAssetDeliveryService::reserveDownload()` locks the entitlement row, re-validates access, and increments `used_count` inside one transaction — BEFORE a single byte streams. A failed file transfer afterward is never treated as grounds to un-consume the reservation.
4. **License keys are individually distinct secrets (Owner Delta §14)**, never modeled as fungible physical Inventory — allocated via `SELECT ... FOR UPDATE SKIP LOCKED`, backstopped by a DB partial unique index (`uq_license_key_pools_one_order_item`) that is the actual source of truth, not merely the application-level idempotency check.
5. **Pin-to-purchased-version**: a Digital Product's purchased `DigitalAsset` version is snapshotted onto the `OrderItem.entitlement_terms_snapshot` at grant time — a later, higher-version asset upload never retroactively changes what an already-granted entitlement points to.

---

## 2. Directory Layout

```
modules/DigitalDelivery/
├── module.json
├── DigitalDeliveryServiceProvider.php
├── Enums/EntitlementType.php
├── Exceptions/{DigitalDeliveryException,EntitlementNotGrantedException,DownloadLimitExceededException}.php
├── Models/{DigitalAsset,CustomerEntitlement,DigitalAccessLog,LicenseKeyPool}.php
├── Contracts/{CustomerEntitlementServiceInterface,DigitalEntitlementGrantHookInterface}.php
├── Services/
│   ├── CustomerEntitlementService.php
│   ├── LicenseKeyAllocationService.php
│   ├── DigitalAssetDeliveryService.php
│   └── DigitalEntitlementGrantHook.php
├── Listeners/GrantEntitlementsOnPaymentCaptured.php
├── Http/Controllers/DigitalDownloadController.php
├── Livewire/ControlCenter/{DigitalAssetManager,LicenseKeyPoolManager}.php
├── Livewire/Storefront/DigitalDownloadsPage.php
└── Routes/web.php
```

## 3. Secure Delivery

`DigitalDownloadController::download()` — a Laravel-signed route (`storefront.digital.download`, `signed` middleware) whose signature is verified before the controller runs at all; the controller then independently re-checks the entitlement belongs to the authenticated Customer (never treats a valid signature alone as authorization, mirroring `MessageAttachmentController`'s precedent), reserves the download via `DigitalAssetDeliveryService`, then streams from the **private** `local` disk (`Storage::disk($asset->disk)->download(...)`). Raw asset ids/paths never appear in the URL — only the signed, entitlement-scoped token.

## 4. License Allocation

`LicenseKeyAllocationService::allocate()` — inside one `DB::transaction()`: check for an existing assignment to this exact `order_item_id` (idempotent retry), else `SELECT ... FOR UPDATE SKIP LOCKED LIMIT 1 WHERE status='available'`, assign it inside a NESTED transaction (a real Postgres savepoint) so a unique-constraint race (two concurrent workers both passing the pre-check before either commits) rolls back only that savepoint and falls through to a recovery lookup returning the actual winning key — never propagates a raw SQL error for a legitimate race outcome.

## 5. Tests

`tests/Feature/DigitalDelivery/{DigitalEntitlementAndDeliveryTest,LicenseKeyAllocationTest}.php`, `tests/Concurrency/{PostgreSqlDigitalDownloadConcurrencyTest,PostgreSqlLicenseAllocationConcurrencyTest}.php`.
