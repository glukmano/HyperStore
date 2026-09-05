# ADR-0146: Booking Capacity Model — Materialized Slots, No Cached Counters

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0146                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-20                              |

## Context

Booking capacity (a resource/slot combination that can hold 1..N simultaneous Bookings) must never double-book under concurrent hold/confirm attempts, must survive a retried request from the same Checkout session without creating a duplicate hold, and must not rely on a cached counter that can drift from the actual set of `held`/`confirmed` rows after a crash or a retried request.

## Decision

`BookingSlot::usedCapacity()` is always a live `COUNT(*) FROM bookings WHERE booking_slot_id = ? AND status IN ('held','confirmed')` — there is no `confirmed_count`/`held_count` column anywhere. A new `Booking` row is created only inside a transaction that first `lockForUpdate()`s the `BookingSlot` row, so the count-then-create sequence is atomic with respect to any other concurrent attempt on the same slot (which blocks on the same lock). The slot's `capacity >= 0` `CHECK` constraint is defense-in-depth for the column itself, not for a counter relationship that doesn't exist.

Hold idempotency uses an **unconditional** `unique(booking_slot_id, checkout_session_uuid)` — not filtered by status — so the same Checkout session retrying its own hold-creation call always returns its existing row, whether that row is still `held` or has since become `confirmed`. This was an explicit correction from an initial design that would have used a status-filtered partial unique index, which would have permitted a second row once the first transitioned out of `held`.

The complete Service/Resource/Slot model (`BookingService` with `product_id UNIQUE`, `BookingResource` with `capacity`, a many-to-many eligibility pivot, `BookingSlot` carrying both `booking_resource_id` and `booking_service_id` since the slot's duration is the *service's* duration) was built out fully before any hold/concurrency logic, so a Customer's slot selection is always provably Product→Service→eligible Resource→Slot, never an ambiguous resource-only lookup.

Slot generation (`BookingSlotGenerationService`) is DST-safe: each `AvailabilityRule` window is parsed via `CarbonImmutable::parse("{$dateStr} {$window['start']}", $tz)` in the resource's own IANA timezone and stored as a UTC instant — proven by a test asserting the same local wall-clock time (10:00 Europe/Zurich) on both sides of a 2027 DST transition while the stored UTC instants differ. Generation is idempotent under `unique(booking_service_id, booking_resource_id, starts_at)` — a re-run for an already-generated period is a safe no-op.

## Consequences

- A regression was found and fixed during implementation: `BookingHoldHook::createHoldsForCheckout()` initially resolved a `CustomerProfile` unconditionally for every Checkout, even ones with zero Booking lines, breaking existing Checkout tests lacking full tenant context. Fixed by adding a cheap `$hasBookingLine` short-circuit before any Customer-identity resolution — a design principle now expected of every future guarded hook of this shape: check "does this checkout even involve my domain" before any side-effecting or context-dependent lookup.
- Booking→Order snapshot fields (`booking_id`, `booking_slot_starts_at_snapshot`, `booking_timezone_snapshot`) are denormalized onto the CartLine's own `metadata` at hold-creation time, mirroring exactly how Auction denormalizes its winning-bid snapshot — so `OrderCreationService`'s OrderItem-creation loop reads all three domains' snapshot fields uniformly with zero hard dependency on B2B/Auctions/Booking models.
- No cached read-performance counter is introduced in this phase; if one is ever added for a busy calendar view, it must be maintained by a DB trigger recomputing from `bookings`, never incremented/decremented by application code, and documented explicitly as a derived cache.

## References

- `modules/Booking/Models/{BookingSlot,Booking}.php`, `modules/Booking/Services/{BookingHoldService,BookingSlotGenerationService,BookingHoldHook}.php`
- `database/migrations/2026_09_07_000118_create_booking_tables.php`
- `tests/Feature/Booking/BookingHoldServiceTest.php`, `tests/Concurrency/PostgreSqlBookingConcurrencyTest.php`
