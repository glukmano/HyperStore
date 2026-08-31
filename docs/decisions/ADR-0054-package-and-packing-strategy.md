# ADR-0054: Package Candidate Modeling and Strategy-Based Packing Architecture

## Status
Accepted

## Context
Shippable items must be grouped into parcel candidates for rate calculation based on weight, dimensions, and shipping classes.

## Decision
1. Define `PackageCandidate` transient DTO containing item lines, total weight, dimensions, source, destination, and package type.
2. Establish `PackingStrategyInterface` with `DefaultPackingService` as baseline implementation:
   - Combines compatible items into a single parcel.
   - Splits packages when max weight, dimensional limits, or incompatible shipping classes require splitting.
3. Keep the packing engine pluggable for future 3D bin-packing plugins.

## Consequences
- Clean separation between packaging logic and carrier rating.
