# Inventory API Documentation

Prefix: `api/v1/inventory`  
Authentication: `auth:sanctum`  
Context: `X-Tenant-ID` header, optional `X-Idempotency-Key` header

---

## 1. Warehouses & Inventory Sources
- `GET /api/v1/inventory/warehouses`: List warehouses for tenant.
- `POST /api/v1/inventory/warehouses`: Create a warehouse.
- `GET /api/v1/inventory/sources`: List inventory sources with associated warehouses.
- `POST /api/v1/inventory/sources`: Create an inventory source.

## 2. Stock Items & Availability
- `GET /api/v1/inventory/stock-items`: List stock items with on_hand, reserved, quarantined, damaged, and incoming quantities.
- `POST /api/v1/inventory/availability`: Context-aware ATS calculation aggregated across eligible sources.

## 3. Reservations Lifecycle
- `POST /api/v1/inventory/reservations/reserve`: Atomically reserve stock across eligible sources with pessimistic row locking.
- `POST /api/v1/inventory/reservations/release`: Release an active reservation.
- `POST /api/v1/inventory/reservations/commit`: Commit a reservation, deducting on_hand and writing movement logs.

## 4. Stock Adjustments & Receiving
- `POST /api/v1/inventory/receive`: Record physical stock receipt with idempotent movement logging.
- `POST /api/v1/inventory/adjustments`: Apply authorized stock adjustments (damage, correction, recount).
- `GET /api/v1/inventory/movements`: Paginated immutable movement audit history.

## 5. Transfers & Reconciliation
- `POST /api/v1/inventory/transfers/dispatch`: Dispatch a transfer, decrementing source on_hand.
- `POST /api/v1/inventory/transfers/receive`: Receive a transfer, incrementing destination on_hand.
- `GET /api/v1/inventory/reconciliation`: Run live dual reconciliation preview.
