# ADR-0017: Hybrid Typed-Value Attribute Storage Architecture

## Status
Accepted

## Context
E-commerce products have diverse specification attributes (e.g. RAM, dimensions, voltage, material, expiry dates, colors). Naive single-table JSON blobs cannot be efficiently indexed, validated, or filtered relationally on PostgreSQL. Conversely, classic uncontrolled EAV (Entity-Attribute-Value) models suffer from type safety loss and query complexity.

## Decision
1. Implement a hybrid relational typed-value storage model:
   - `attributes`: Defines the specification attribute, technical code, and data type (`text`, `textarea`, `integer`, `decimal`, `boolean`, `date`, `datetime`, `select`, `multiselect`, `color`, `measurement`, `url`, `file`).
   - `attribute_options`: Stores discrete option keys for `select` and `multiselect` types.
   - `product_attribute_values`: Strongly typed value table storing scalar values (`text_value`, `int_value`, `decimal_value`, `boolean_value`, `date_value`, `datetime_value`, `file_path`).
   - `product_attribute_options`: Relational pivot table connecting `product_id`, `attribute_id`, and `attribute_option_id` for multi-value and single-value selections.
2. Filter queries on selectable attributes use direct indexed relational joins (`WHERE attribute_id = 5 AND attribute_option_id IN (10, 12)`) without JSON table scans.
3. Application services and database constraints enforce value integrity matching `attribute.type`.

## Consequences
- Maximum query efficiency and indexing on PostgreSQL.
- Type safety guaranteed at both database and application layers.
- Seamless compatibility with future faceted search and Meilisearch indexing.
