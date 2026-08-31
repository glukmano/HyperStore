# Inventory Module Specification

**Module Namespace**: `Modules\Inventory`  
**Root Path**: `modules/Inventory/`  
**Status**: Active Production Module (PHASE-05)

---

## 1. Overview & Architectural Boundaries

The `Inventory` module owns physical and logical stock truth, inventory sources, multi-source availability (ATS), inventory movements ledger, reservations, transfers, and discrepancy reconciliation.

### Key Invariants:
1. **Decimal Precision**: All quantities use `NUMERIC(14, 4)` in PostgreSQL and are manipulated via the immutable `Quantity` Value Object with `bcmath` (scale 4). Construction from binary float is strictly forbidden.
2. **Warehouse vs InventorySource**:
   - `warehouses`: Physical facilities with addresses and timezones.
   - `inventory_sources`: Logical stock entities (`warehouse`, `vendor`, `supplier`, `3pl`, `dropship`, `virtual`). StockItems belong to InventorySources.
3. **Pessimistic Row Locking**: All reservations and inventory deductions lock target `stock_items` rows in deterministic ID ascending order with `SELECT ... FOR UPDATE` inside atomic DB transactions to eliminate overselling and deadlocks.
4. **Immutable Movement Ledger**: Every inventory delta creates an `inventory_movements` record. Corrections use compensating movements.
5. **Multi-Source Split Allocations**: `inventory_reservations` represents logical reservations, while `inventory_reservation_allocations` splits quantities across eligible sources.
6. **Dual Reconciliation**: Audits `on_hand` against movement sums AND audits `reserved` against active reservation allocation totals.

---

## 2. Directory Layout

```
modules/Inventory/
├── module.json
├── InventoryServiceProvider.php
├── ValueObjects/
│   └── Quantity.php
├── Contracts/
│   ├── InventoryAvailabilityServiceInterface.php
│   ├── InventoryReservationServiceInterface.php
│   ├── InventoryAdjustmentServiceInterface.php
│   └── InventoryTransferServiceInterface.php
├── DTOs/
│   ├── InventoryContext.php
│   ├── AvailabilityResultDTO.php
│   └── ReservationResultDTO.php
├── Models/
│   ├── Warehouse.php
│   ├── InventorySource.php
│   ├── UnitOfMeasure.php
│   ├── StockItem.php
│   ├── InventoryMovement.php
│   ├── InventoryOperationKey.php
│   ├── InventoryReservation.php
│   ├── InventoryReservationAllocation.php
│   ├── InventoryTransfer.php
│   └── InventoryTransferItem.php
├── Services/
│   ├── InventoryAvailabilityService.php
│   ├── InventoryReservationService.php
│   ├── InventoryAdjustmentService.php
│   ├── InventoryTransferService.php
│   ├── InventoryReconciliationService.php
│   └── InventoryIdempotencyService.php
├── Commands/
│   ├── ExpireReservationsCommand.php
│   └── ReconcileInventoryCommand.php
├── Livewire/
│   ├── WarehouseManager.php
│   ├── InventorySourceManager.php
│   ├── StockItemManager.php
│   ├── InventoryMovementHistory.php
│   ├── ReservationManager.php
│   ├── TransferManager.php
│   ├── InventoryAdjustmentManager.php
│   ├── InventoryReceivingManager.php
│   └── InventoryReconciliationManager.php
├── Resources/
│   └── views/livewire/
└── Routes/
    ├── api.php
    └── web.php
```
