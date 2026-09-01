PHASE-06 is VERY CLOSE to acceptance, but the current walkthrough is not sufficient
for final closure.

DO NOT begin PHASE-07.

Do NOT perform another broad rewrite.

Perform a FINAL SOURCE-LEVEL COMPLIANCE AUDIT and fix only any remaining gaps.

Return:

PHASE-06 FINAL CLOSURE REPORT

The report MUST explicitly prove the following:

1. Inventory contract boundary:
   - no direct InventorySource Eloquent dependency from Fulfillment planning
   - source-specific availability
   - stale-source exclusion
   - Store/Market/Channel eligibility

2. Fulfillment readiness:
   - ready
   - partial
   - backordered
   - preorder
   - unavailable
   - non_physical
   State exactly which are implemented and tested.
   Do not claim unsupported states.

3. Quantity splitting:
   Example:
   qty 10
   source A = 6
   source B = 4
   → deterministic 6 + 4 allocation.

4. Deterministic fulfillment:
   same normalized input/state produces identical groups, keys and ordering.

5. Shipping method context eligibility:
   Tenant + Store + Market + Channel.

6. Restriction engine:
   prove product, shipping class, source, zone and method restrictions are evaluated
   conditionally rather than acting as global bans.

7. Currency correctness:
   - method amounts interpreted in method.currency
   - conversion via Phase-04 CurrencyConversionInterface
   - all RateBreakdown components preserved after conversion
   - final reconciliation exact.

8. Typed Promotion FreeShipping integration:
   no class-name sniffing
   no arbitrary array heuristics
   restrictions cannot be bypassed.

9. Table-rate registries:
   registered condition types
   registered action types
   unknown types rejected
   fixed_amount
   per_item
   per_weight_step
   per_package
   deterministic precedence.

10. Local Pickup:
    - mapped source/location
    - actual source availability
    - source-method compatibility
    - unavailable stock does not produce pickup quote.

11. Local Delivery:
    - zone/postal eligibility
    - invalid destination rejected
    - no external geocoder dependency.

12. Carrier architecture:
    explicitly list implemented normalized contracts/DTOs for:
    - service discovery
    - rates
    - tracking
    - label request/result
    - cancel label
    - provider errors/capabilities

    These may remain extension contracts only.
    Rate quote MUST NOT create labels.

13. Carrier provider failure isolation:
    provide test result for:

    Provider A times out
    Provider B succeeds
    Static/manual method succeeds

    Expected:
    valid alternatives returned.

    Also test:
    all providers fail
    → structured safe result
    → no raw provider exception leakage.

14. Carrier service selection:
    prove no `$rates[0]` / provider-order dependency.
    Explicit service binding or normalized multiple-service quoting.

15. Carrier money breakdown:
    separate:
    - provider/base carrier rate
    - markup amount
    - markup percentage
    - handling
    - final rate.

16. Carrier credentials:
    prove:
    - encrypted at rest
    - API never returns secret
    - Livewire never hydrates/displays saved secret
    - audit contains no secret
    - logs contain no secret
    - wrong Tenant cannot access/update
    - rotation never reveals old value
    - decryption failure throws controlled domain exception.

17. Packing:
    prove:
    - quantity splitting
    - oversized single unit failure
    - max package weight
    - dimensional limits
    - shipping-class incompatibility
    - PackageType use
    - deterministic output.

18. Catalog shipping capability:
    confirm external API cannot decide business truth using trusted `is_shippable`.
    Product/variant shipping capability must come from Catalog contracts/registry.

19. Product/variant/source IDOR:
    prove cross-Tenant product, variant and InventorySource inputs are rejected.

20. Functional Control Center:
    list actual functional screens/actions for:
    - zones
    - methods
    - rate rules
    - classes
    - package types
    - carriers/services
    - credentials
    - pickup
    - restrictions
    - rate preview
    - fulfillment source config
    - fulfillment strategy
    - fulfillment preview

    Explicitly confirm no placeholder-only screens remain for required Phase-06 UI.

21. Management API:
    list actual endpoints implemented for all Phase-06 management resources.

22. RBAC:
    list negative API AND Livewire tests.

23. Audit:
    prove audit coverage for:
    - zones
    - methods
    - rates
    - restrictions
    - carrier config
    - credential rotation with secret redaction
    - package types
    - source-method mappings
    - fulfillment strategies.

24. Events:
    list actual emitted events and tests.

25. Observability:
    prove structured diagnostics for:
    - quote latency
    - provider latency/failure code
    - eligible/rejected rate counts
    - fulfillment split count
    without secrets/full address leakage.

26. Database tenant integrity:
    prove or document guards/constraints preventing:
    - Tenant A method → Tenant B zone
    - Tenant A pickup → Tenant B source
    - Tenant A source-method mapping → Tenant B entities
    - Tenant A fulfillment config → Tenant B source
    - Tenant A carrier credential/service misuse.

27. Quote purity:
    show test proves no changes to:
    - stock on_hand/reserved
    - movements
    - reservations
    - shipping configuration
    - credentials
    - shipment/order/checkout persistence.

28. Fulfillment planning purity:
    same inventory mutation proof.

29. Source audit results:
    search and report:
    - tenant fallback
    - unscoped mutation findOrFail
    - binary float
    - eval
    - arbitrary provider class execution
    - plaintext secrets
    - raw provider exceptions
    - trusted external is_shippable
    - Inventory mutations in quote/planning
    - Cart/Checkout/Order leakage.

30. Final quality results:
    - PHASE-06-specific test count
    - total tests
    - assertions
    - PHPStan level 8
    - Pint
    - Vite
    - Composer audit
    - npm audit
    - migrate:fresh --seed
    - rollback ONLY Phase-06 migrations
    - clean re-migration.

31. Git commit hash.

32. Explicitly confirm:
    - no PHASE-07 implementation
    - no Cart
    - no Checkout
    - no Orders
    - no Payments
    - no real label purchase.

If any item above is not implemented, FIX IT before the final report.

Then STOP.

DO NOT begin PHASE-07.