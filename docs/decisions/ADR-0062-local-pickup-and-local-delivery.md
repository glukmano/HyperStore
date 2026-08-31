# ADR-0062: Local Pickup and Local Delivery Commercial Domain Models

## Status
Accepted

## Context
Merchants require click-and-collect (Local Pickup) and local courier delivery.

## Decision
1. Local Pickup maps to existing `Warehouse` or `InventorySource` records with pickup instructions, fee, and store scope.
2. Local Pickup is only offered when the pickup location has available inventory.
3. Local Delivery matches by zone, postal code prefix, or distance hook with flat or rule-based fee.

## Consequences
- First-party omni-channel pickup and delivery support.
