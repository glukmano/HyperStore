# Booking Module Specification

**Module Namespace**: `Modules\Booking`
**Root Path**: `modules/Booking/`
**Status**: Active Production Module (PHASE-20)

---

## 1. Overview & Architectural Boundaries

The `Booking` module adds appointment/resource-slot commerce for a Booking Product, integrating through the existing Catalog/Checkout/Order pipeline. See `docs/decisions/ADR-0146-booking-capacity-live-count-no-cached-counters.md` for the full rationale.

### Key Invariants:

1. **No cached capacity counters**: `BookingSlot::usedCapacity()` is always a live `COUNT(*)` over `bookings` (`held`/`confirmed`), computed inside the same row lock that creates a new `Booking`.
2. **Unconditional hold idempotency**: `unique(booking_slot_id, checkout_session_uuid)` is not status-filtered — the same Checkout session retrying its hold call always gets back the same row, whether it's still `held` or has since become `confirmed`.
3. **Complete Service/Resource/Slot model**: `BookingService` (duration/timezone/buffer, `product_id UNIQUE`) — many-to-many — `BookingResource` (capacity), so a slot selection is always provably Product→Service→eligible Resource→Slot.
4. **DST-safe slot generation**: generated from `AvailabilityRule` in the resource's own IANA timezone, stored as UTC; idempotent under `unique(booking_service_id, booking_resource_id, starts_at)`.
5. **No parallel Order engine**: a confirmed Booking is an ordinary `Order` via the existing Checkout/OrderCreationService pipeline; slot-start/timezone snapshot frozen onto `order_items`.

---

## 2. Directory Layout

```
modules/Booking/
├── module.json
├── BookingServiceProvider.php
├── Enums/BookingStatus.php
├── Exceptions/{BookingException,SlotCapacityExceededException}.php
├── Models/{BookingService,BookingResource,AvailabilityRule,AvailabilityException,BookingSlot,Booking}.php
├── Contracts/{BookingHoldHookInterface,BookingOrderConfirmationHookInterface}.php
├── Services/
│   ├── BookingSlotGenerationService.php
│   ├── BookingHoldService.php
│   ├── BookingHoldHook.php
│   └── BookingOrderConfirmationHook.php
├── Livewire/ControlCenter/BookingResourceManager.php
├── Livewire/Storefront/BookingWidget.php
├── Console/Commands/{ExpireBookingHoldsCommand,GenerateBookingSlotsCommand}.php
└── Routes/web.php
```

## 3. Hold Lifecycle

`BookingHoldService::hold()` — inside one `DB::transaction()`: lock the `BookingSlot` row, look up an existing `Booking` by `(booking_slot_id, checkout_session_uuid)` (idempotent return), else recompute live used-capacity and, if under `capacity`, create a new `held` Booking. `expireHeldPastDeadline()` (via `bookings:expire-holds`, `everyMinute()`) transitions expired `held` rows to `cancelled` — a real, queryable transition, not a lazy read-time check. `confirmForCheckoutSession()` transitions to `confirmed` on payment success.

## 4. Checkout Integration

`BookingHoldHookInterface::createHoldsForCheckout()` is called from `CheckoutOrchestrator::reserveInventory()` (create), `CheckoutOrchestrator::cancel()` and `CheckoutExpirationService::expireIfNeeded()` (cancel) — all guarded by `app()->bound()`. It short-circuits immediately (before any Customer-identity resolution) when the Checkout carries zero Booking lines, and denormalizes `booking_id`/`booking_slot_starts_at_snapshot`/`booking_timezone_snapshot` onto the originating CartLine's `metadata` after creating each hold.

## 5. Slot Generation

`GenerateBookingSlotsCommand` (`bookings:generate-slots`, daily) drives `BookingSlotGenerationService::generateForServiceResource()`, parsing each `AvailabilityRule` window in the resource's own timezone via `CarbonImmutable::parse()` and storing UTC instants — proven DST-safe by a test spanning a 2027 Europe/Zurich spring-forward transition.

## 6. Tests

`tests/Feature/Booking/BookingHoldServiceTest.php`, `tests/Concurrency/PostgreSqlBookingConcurrencyTest.php`.
