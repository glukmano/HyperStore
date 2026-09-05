# ADR-0145: Auction Bid Immutability, Row-Locking, and DB-Authoritative Time

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0145                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-20                              |

## Context

Auction bidding must serialize concurrent bids on the same lot so exactly one becomes the winning bid, must derive the winner from a source that can never be second-guessed by a race, must compare against a close-time that a client cannot spoof, and must not let the auctioned Product be sold through the ordinary catalog while bidding is open or while a winner is settling.

## Decision

**Immutability over mutation**: `Bid` rows have no `status` column and are never updated after creation — enforced twice: an application-level boot hook (`updating`/`deleting` throw `AuctionException`) and a Postgres trigger (`prevent_bid_mutation()`) blocking any `UPDATE`/`DELETE` at the database layer, as defense-in-depth against a future code path that bypasses the Eloquent model. The current winner is derived **exclusively** from `Auction.current_bid_id` — a foreign key the Auction row's own atomic update repoints on each accepted bid. A historical Bid is written once and never touched again; "you were outbid" is a computed UI comparison (`bid.id !== auction.current_bid_id`), never a persisted mutation.

**Pessimistic locking over optimistic compare-and-swap**: bid placement locks the `Auction` row (`lockForUpdate()`) inside one `DB::transaction()`, identical to the `LoyaltyAccountLock`/checkout-session-locking idiom already proven elsewhere in the codebase — chosen over an optimistic `version`-column conditional update because a rejected bid must still see the *current* floor to give the bidder an accurate "bid too low, current floor is X" response, which an optimistic retry loop would complicate for no real benefit at this contention level.

**Database-authoritative time**: `DatabaseClock::now()` queries `SELECT CURRENT_TIMESTAMP AS now` via the same connection, inside the same locked transaction, for both the bid-floor calculation and the close comparison — never PHP `Carbon::now()`, never a client-supplied timestamp.

**Eligibility gate**: `AuctionEligibilityGate` blocks ordinary Add-to-Cart for an Auction Product while `scheduled`/`active`, with exactly one narrow, non-client-controllable exception — a CartLine whose own server-authored `metadata['auction_id']` is already set (only ever written by `AuctionSettlementService`, never by request input). Inventory for the lot is reserved at **activation** (not close) via the existing `InventoryReservationServiceInterface`, and the winner's system-generated CheckoutSession has its `reservation_references` pre-populated with that same key — `OrderCreationService`'s existing, unmodified adopt-loop then hands off the reservation with zero special-casing.

## Consequences

- The Owner-Delta-mandated real-PostgreSQL concurrency test (`PostgreSqlAuctionConcurrencyTest`) initially had an incorrect expectation (both concurrent identical-amount bids should succeed) — corrected to assert exactly one success and one `BidTooLowException` rejection, since the second bidder's own amount no longer clears the floor once the first bid commits. This proves genuine serialization, not merely "both eventually wrote something."
- `AuctionProductType::requiresShipping()` returns `true` and `AuctionLifecycleService::ensurePlaceholderPrice()` provisions a minimal, never-charged placeholder Price at activation — needed only because `CheckoutShippingOrchestrator::quote()` independently calls the ordinary `PriceResolver` for shipping-rate matching, a second, non-authoritative lookup unaware of the Auction override; the actual charged amount always remains the winning bid via `CheckoutPricingOrchestrator`'s override.
- No parallel `AuctionOrder` model — the resulting Order is an ordinary `Order`; `order_items` carries an immutable auction/winning-bid snapshot (`auction_id, winning_bid_id, winning_bid_amount_minor, auction_currency_snapshot, reserve_met`) that a later Auction edit never alters.

## References

- `modules/Auctions/Models/{Auction,Bid}.php`, `modules/Auctions/Services/{DatabaseClock,AuctionBiddingService,AuctionLifecycleService,AuctionSettlementService,AuctionEligibilityGate}.php`
- `database/migrations/2026_09_07_000117_create_auction_tables.php` (trigger definition)
- `tests/Feature/Auctions/AuctionBiddingTest.php`, `tests/Concurrency/PostgreSqlAuctionConcurrencyTest.php`
