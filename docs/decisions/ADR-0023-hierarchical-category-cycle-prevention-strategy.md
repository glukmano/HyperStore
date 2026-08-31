# ADR-0023: Hierarchical Category Cycle Prevention Strategy

## Status
Accepted

## Context
Hierarchical category structures with `parent_id` foreign keys can accidentally become cyclic (e.g. Category A -> Category B -> Category A), causing infinite recursion in menu rendering, breadcrumbs, and search aggregations.

## Decision
1. Adopt an adjacency list hierarchy model (`parent_id`) with database foreign key cascade protection.
2. Implement `CategoryHierarchyService` with cycle-detection algorithm:
   - Rejects setting `parent_id` equal to category's own `id`.
   - Rejects setting `parent_id` to any descendant category by traversing ancestry path prior to persistence.
   - Enforces parent and child categories belong to the identical `tenant_id`.
3. Add automated Pest unit and feature tests covering cyclic mutation attempts.

## Consequences
- Lightweight relational storage without complex graph database dependencies.
- Mathematically guaranteed cycle-free taxonomy trees.
