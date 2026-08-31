# ADR-0060: Commercial Table-Rate Rule Condition/Action Architecture

## Status
Accepted

## Context
Complex shipping pricing requires table-rate rules evaluating weight, price, item quantity, and shipping class.

## Decision
1. Store typed rule conditions (`min_weight`, `max_weight`, `min_subtotal`, `max_subtotal`, `min_quantity`, `max_quantity`, `shipping_class_id`).
2. Store typed rule actions (`fixed_amount`, `per_item_amount`, `per_weight_amount`, `per_package_amount`, `percentage_fee`).
3. Forbid arbitrary PHP/SQL code execution or `eval()`.
4. Rules evaluate in configured priority order with optional stop-processing flags.

## Consequences
- Safe, typed, and performant commercial table-rate engine.
