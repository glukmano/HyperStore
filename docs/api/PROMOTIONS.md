# Promotions API Documentation

Prefix: `api/v1/promotions`  
Authentication: `auth:sanctum`  
Context: `X-Tenant-ID` header

---

## 1. Promotions & Coupons
- `GET /api/v1/promotions`: List promotions with conditions and actions.
- `GET /api/v1/promotions/coupons`: List active coupons.

## 2. Evaluation Engine
- `POST /api/v1/promotions/evaluate`: Evaluates a cart array against active promotions and coupons, calculating line discounts, total savings, and final payable total.
