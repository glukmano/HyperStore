# ADR-0032: Promotion Rule Engine Registry Design

## Status
Accepted

## Context
Promotions must be dynamically evaluated based on conditions (cart items, customer group, date, coupon) and actions (percentage, fixed amount, Buy X Get Y). Hardcoding rules in database PHP strings is forbidden.

## Decision
1. Create `modules/Promotions/` with `PromotionConditionRegistry` and `PromotionActionRegistry`.
2. Conditions and Actions implement typed contracts (`PromotionConditionInterface`, `PromotionActionInterface`).
3. Registered condition types: `product`, `category`, `brand`, `product_type`, `customer_group`, `store`, `market`, `channel`, `min_quantity`, `min_cart_amount`, `coupon`.
4. Registered action types: `percentage_discount`, `fixed_discount`, `fixed_price`, `buy_x_get_y`, `free_shipping`.

## Consequences
- Open/Closed principle: plugins can register custom conditions and actions at boot without schema alterations.
