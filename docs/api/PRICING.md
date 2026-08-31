# Pricing API Documentation

Prefix: `api/v1/pricing`  
Authentication: `auth:sanctum`  
Context: `X-Tenant-ID` header

---

## 1. Price Books
- `GET /api/v1/pricing/price-books`: List price books for tenant.
- `POST /api/v1/pricing/price-books`: Create a price book.

## 2. Prices & Resolution
- `GET /api/v1/pricing/prices`: List prices with tier breaks and price books.
- `POST /api/v1/pricing/resolve`: Deterministically resolves best selling price, compare-at price, applied tier, and explanation trace.

## 3. Currency Conversion
- `GET /api/v1/pricing/exchange-rates`: List configured currency exchange rates.
- `POST /api/v1/pricing/convert-currency`: Converts an amount between currencies.

## 4. Taxation
- `POST /api/v1/pricing/tax-calculate`: Calculates net, gross, and tax amounts for a given tax class and destination zone in inclusive/exclusive mode.
