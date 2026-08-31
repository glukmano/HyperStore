# ADR-0027: Price Book Architecture

## Status
Accepted

## Context
A single canonical product may be priced differently depending on Store, Market, Channel, Customer Group, or special promotional seasons.

## Decision
1. Price Books (`price_books`) group price rules under a Tenant with optional scoping:
   - `store_id` (nullable)
   - `market_id` (nullable)
   - `channel_id` (nullable)
   - `customer_group_id` (nullable)
   - `currency`
   - `priority` (integer, higher priority matches first)
   - `valid_from` / `valid_until`
2. Every tenant has at least one default Base Price Book.

## Consequences
- Eliminates the need to duplicate products to support localized, channel-specific, or B2B pricing.
