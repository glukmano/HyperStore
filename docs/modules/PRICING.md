# Pricing Module Specification

**Module Namespace**: `Modules\Pricing`  
**Root Path**: `modules/Pricing/`  
**Status**: Active Production Module (PHASE-04)

---

## 1. Overview & Architectural Boundaries

The `Pricing` module owns commercial pricing, multi-currency conversion, price books, quantity tiers, cost tracking, and international taxation for HyperStore.

### Key Invariants:
1. **Float-Free Money**: All monetary amounts are integer minor units or computed using `brick/money:^0.14.2` with explicit half-up rounding.
2. **Hierarchical Price Resolution**:
   - Variant-specific Price in active scoped Price Book
   - Quantity Tier breaks (when requested quantity >= min_quantity)
   - Canonical Product Price in matching Price Book
   - Fallback to Base Price Book
3. **Multi-Currency Abstraction**: Decoupled `CurrencyConversionInterface` and `ExchangeRateProviderInterface`.
4. **Tax Calculation Engine**: Supports Tax Classes, Tax Zones, Tax Rates, and exact Tax-Inclusive vs Tax-Exclusive calculations.
5. **Cost/Margin Security**: `cost_minor` and computed margins are restricted via permission `pricing.cost.view`.

---

## 2. Directory Layout

```
modules/Pricing/
├── module.json
├── PricingServiceProvider.php
├── ValueObjects/
│   └── MoneyValue.php
├── Contracts/
│   ├── PriceResolverInterface.php
│   ├── CurrencyConversionInterface.php
│   └── TaxCalculatorInterface.php
├── DTOs/
│   ├── PricingContext.php
│   ├── PricingItem.php
│   ├── PriceResult.php
│   ├── TaxContext.php
│   └── TaxResult.php
├── Models/
│   ├── PriceBook.php
│   ├── Price.php
│   ├── TierPrice.php
│   ├── ExchangeRate.php
│   ├── TaxClass.php
│   ├── TaxZone.php
│   └── TaxRate.php
├── Services/
│   ├── PriceResolver.php
│   ├── CurrencyConversionService.php
│   └── TaxCalculator.php
├── Livewire/
│   ├── PriceBookManager.php
│   ├── ProductPricingManager.php
│   ├── ExchangeRateManager.php
│   └── TaxManager.php
├── Resources/
│   └── views/livewire/
└── Routes/
    ├── api.php
    └── web.php
```
