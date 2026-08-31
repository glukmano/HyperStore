# ADR-0029: Multi-Currency Exchange-Rate Abstraction

## Status
Accepted

## Context
HyperStore supports multi-currency selling where a base currency price may need dynamic or fixed conversion into a customer's display/checkout currency.

## Decision
1. Implement `ExchangeRateProviderInterface` and `CurrencyConversionService`.
2. Store rates in `exchange_rates (tenant_id, base_currency, target_currency, rate, effective_at, source, is_stale)`.
3. Support manual admin rates and future automated external sync adapters without hardcoding external FX providers.
4. Support stale rate threshold detection (e.g. alert if rates are older than configured TTL).

## Consequences
- Fully decoupled currency conversion with audit trails and stale rate safeguards.
