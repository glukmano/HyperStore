# Auctions Module Specification

**Module Namespace**: `Modules\Auctions`
**Root Path**: `modules/Auctions/`
**Status**: Active Production Module (PHASE-20)

---

## 1. Overview & Architectural Boundaries

The `Auctions` module adds bid-based commerce for a single-lot Auction Product, integrating through the existing Catalog/Inventory/Checkout/Order pipeline. See `docs/decisions/ADR-0145-auction-bid-immutability-db-time-eligibility-gate.md` for the full rationale.

### Key Invariants:

1. **Bid rows are append-only, never mutated**: no `status` column; enforced by both an Eloquent boot-hook guard and a Postgres trigger (`prevent_bid_mutation()`). The winner is derived exclusively from `Auction.current_bid_id`.
2. **Database-authoritative time**: bid-floor and close comparisons read `SELECT CURRENT_TIMESTAMP` via the same connection inside the same locked transaction as the bid — never PHP `Carbon::now()`.
3. **Eligibility gate**: an Auction Product cannot be added to an ordinary Cart while `scheduled`/`active`, except via the system-generated winner CartLine (`metadata['auction_id']`, server-authored only).
4. **Inventory reserved at activation, not close**: `AuctionInventoryReservationService::reserveAtActivation()` reserves the lot the moment an Auction goes `active`, using the existing `InventoryReservationServiceInterface`; the winner's Checkout hands this same reservation off into the ordinary Order reservation lifecycle with zero special-casing in `OrderCreationService`.
5. **No parallel Order engine**: a closed Auction produces an ordinary `Order` via the existing Checkout/OrderCreationService pipeline; the winning-bid snapshot is frozen onto `order_items`.

---

## 2. Directory Layout

```
modules/Auctions/
├── module.json
├── AuctionsServiceProvider.php
├── Enums/{AuctionStatus,UnpaidWinnerPolicy}.php
├── Exceptions/{AuctionException,AuctionClosedException,BidTooLowException,AuctionNotEligibleForCartException}.php
├── Models/{Auction,Bid}.php
├── Contracts/{AuctionEligibilityGateInterface,AuctionOrderSettlementHookInterface}.php
├── Services/
│   ├── DatabaseClock.php
│   ├── AuctionBiddingService.php
│   ├── AuctionInventoryReservationService.php
│   ├── AuctionLifecycleService.php
│   ├── AuctionSettlementService.php
│   ├── AuctionEligibilityGate.php
│   └── AuctionOrderSettlementHook.php
├── Livewire/ControlCenter/AuctionManager.php
├── Livewire/Storefront/AuctionBidWidget.php
├── Console/Commands/ProcessAuctionLifecycleCommand.php
└── Routes/web.php
```

## 3. Bidding

`AuctionBiddingService::placeBid()` — inside one `DB::transaction()`: lock the `Auction` row (`lockForUpdate()`), read `DatabaseClock::now()`, reject if closed or the amount doesn't clear `current_bid.amount_minor + bid_increment_minor` (or `starting_price_minor` if no bid yet), create the immutable `Bid` row, repoint `Auction.current_bid_id/current_price_minor/current_bidder_customer_profile_id`.

## 4. Lifecycle

`ProcessAuctionLifecycleCommand` (`auctions:process-lifecycle`, `everyMinute()`) drives `AuctionLifecycleService`: `activateScheduled()` (reserves Inventory, provisions a placeholder Price used only by the unrelated shipping-rate lookup — never the charged amount), `closeEnded()` → `AuctionSettlementService::createWinnerCheckout()` (idempotent, bound to the winning bidder via the existing `CheckoutOwnershipService`), `cancelUnpaidWinners()` (per `unpaid_winner_policy`, currently `Cancel` only).

## 5. Checkout Integration

`CheckoutPricingOrchestrator::calculate()` substitutes `metadata['winning_bid_amount_minor']` for the Auction cart line's price. `CartService::addLine()` consults `AuctionEligibilityGateInterface` (guarded by `app()->bound()`). `CheckoutOrchestrator::reserveInventory()` skips its normal `reserve()` call when `checkout_sessions.auction_id !== null`, since the reservation was already made at activation.

## 6. Tests

`tests/Feature/Auctions/AuctionBiddingTest.php`, `tests/Concurrency/PostgreSqlAuctionConcurrencyTest.php`.
