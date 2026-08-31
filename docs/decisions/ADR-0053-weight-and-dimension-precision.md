# ADR-0053: Weight and Dimension Exact Decimal Precision and Unit Semantics

## Status
Accepted

## Context
Physical goods require accurate weight and dimensional calculations for parcel constraints and table rates.

## Decision
1. Implement `Weight` and `Dimension` Value Objects using string-based decimal arithmetic (`NUMERIC(14, 4)` / `bcmath` scale 4).
2. Support units: Weight (`g`, `kg`, `oz`, `lb`), Dimensions (`mm`, `cm`, `m`, `in`).
3. Binary floats are strictly prohibited.

## Consequences
- Exact parcel threshold evaluations and weight calculations without numeric precision loss.
