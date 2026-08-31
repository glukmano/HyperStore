# Promotions Module Specification

**Module Namespace**: `Modules\Promotions`  
**Root Path**: `modules/Promotions/`  
**Status**: Active Production Module (PHASE-04)

---

## 1. Overview & Architectural Boundaries

The `Promotions` module provides an extensible rule engine for discounts, marketing campaigns, and promo coupons.

### Key Invariants:
1. **Rule Engine Extensibility**: Condition and Action registries (`PromotionConditionRegistry`, `PromotionActionRegistry`) allow plugin expansion without database schema alterations.
2. **Multi-Currency Safety**: Fixed discounts are converted via `CurrencyConversionInterface` when cart currency differs from discount definition.
3. **Coupons**: Case-normalized, unique per tenant, with total and per-customer usage limits.
4. **Stacking & Exclusivity**: Evaluated in descending order of priority. Exclusive promotions halt subsequent rule processing.

---

## 2. Directory Layout

```
modules/Promotions/
├── module.json
├── PromotionsServiceProvider.php
├── Contracts/
│   ├── PromotionConditionInterface.php
│   └── PromotionActionInterface.php
├── Registries/
│   ├── PromotionConditionRegistry.php
│   └── PromotionActionRegistry.php
├── Conditions/
│   ├── MinCartAmountCondition.php
│   ├── MinQuantityCondition.php
│   ├── ProductCondition.php
│   └── CouponCondition.php
├── Actions/
│   ├── PercentageDiscountAction.php
│   ├── FixedDiscountAction.php
│   └── BuyXGetYAction.php
├── DTOs/
│   ├── PromotionCartItem.php
│   ├── PromotionContext.php
│   ├── DiscountLine.php
│   └── PromotionResult.php
├── Models/
│   ├── Promotion.php
│   ├── PromotionCondition.php
│   ├── PromotionAction.php
│   └── Coupon.php
├── Services/
│   └── PromotionRuleEngine.php
├── Livewire/
│   ├── PromotionManager.php
│   └── CouponManager.php
├── Resources/
│   └── views/livewire/
└── Routes/
    ├── api.php
    └── web.php
```
