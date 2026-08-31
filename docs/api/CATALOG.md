# Catalog API Documentation

All Catalog API routes are module-owned under `modules/Catalog/Routes/api.php` with prefix `api/v1/catalog`.
Every route requires authentication (`auth:sanctum`), tenant context resolution (`X-Tenant-ID` header or domain addressing), and explicit permission authorization.

---

## 1. Product Types

### `GET /api/v1/catalog/product-types`
Lists all 22 first-party product types and their capability definitions.
- **Permission**: None (Public/Authenticated)
- **Response**:
```json
{
  "data": [
    {
      "id": "physical",
      "name": "Physical Product",
      "description": "Tangible goods requiring physical shipping and inventory.",
      "capabilities": {
        "requiresShipping": true,
        "supportsInventory": true,
        "supportsVariants": true,
        "supportsDownloads": false,
        "supportsRecurringBilling": false,
        "supportsCustomerInput": false,
        "supportsBooking": false,
        "supportsLicenseDelivery": false,
        "supportsAuction": false,
        "supportsQuote": false,
        "supportsCustomization": false
      }
    }
  ]
}
```

### `GET /api/v1/catalog/product-types/{id}`
Returns capability details for a single product type.

---

## 2. Products

### `GET /api/v1/catalog/products`
Lists products with pagination, search, and filtering.
- **Query Parameters**:
  - `page` (int, default 1)
  - `per_page` (int, max 100)
  - `type` (string, e.g. `physical`, `digital`)
  - `status` (string: `active`, `draft`, `inactive`)
  - `brand_id` (int)
  - `category_id` (int)
  - `search` (string: matches SKU or localized name)
- **Permission**: `products.view` or `catalog.view`

### `POST /api/v1/catalog/products`
Creates a canonical product with localized translations and category assignments.
- **Permission**: `products.create` or `catalog.manage`
- **Request Body**:
```json
{
  "product_type": "physical",
  "sku": "TSHIRT-RED-M",
  "barcode": "1234567890123",
  "brand_id": 1,
  "attribute_set_id": 1,
  "status": "active",
  "category_ids": [1, 2],
  "primary_category_id": 1,
  "translations": {
    "en": {
      "name": "Red Cotton T-Shirt",
      "short_description": "Comfortable 100% cotton tee",
      "description": "Full product description here..."
    },
    "ar": {
      "name": "قميص قطني أحمر",
      "short_description": "تي شيرت قطن 100% مريح",
      "description": "وصف كامل للمنتج هنا..."
    }
  }
}
```
- **Response**: `201 Created`

### `GET /api/v1/catalog/products/{id}`
Returns full product details, variants, typed attribute values, relational options, and store publication status.
- **Permission**: `products.view` or `catalog.view`

### `PUT /api/v1/catalog/products/{id}`
Updates canonical product details.
- **Permission**: `products.update` or `catalog.manage`

### `DELETE /api/v1/catalog/products/{id}`
Retires and archives the product (sets status to `archived` and hides it from all active store listings).
- **Permission**: `products.archive` or `catalog.manage`
- **Response**: `200 OK`

---

## 3. Product Store Publication & Availability

### `POST /api/v1/catalog/products/{id}/publish`
Publishes or updates a canonical product's availability on a specific Store, Market(s), and Channel(s).
- **Permission**: `products.update` or `catalog.manage`
- **Request Body**:
```json
{
  "store_id": 1,
  "status": "published",
  "visibility": "visible",
  "is_featured": true,
  "sort_order": 0,
  "market_ids": [1],
  "channel_ids": [1],
  "translations": {
    "en": {
      "slug": "red-cotton-t-shirt",
      "name": "Red Cotton T-Shirt (US Store)"
    },
    "ar": {
      "slug": "red-cotton-t-shirt-ar",
      "name": "قميص قطني أحمر"
    }
  }
}
```

---

## 4. Typed Attributes & Relational Options

### `POST /api/v1/catalog/products/{id}/attributes`
Assigns typed values (`text`, `int`, `decimal`, `boolean`, `date`, `datetime`, `file_path`) and relational multiselect options without scanning JSON.
- **Permission**: `products.update` or `catalog.manage`

---

## 5. Variants

### `POST /api/v1/catalog/products/{id}/variants`
Creates a product variant with an order-independent SHA-256 combination hash.
- **Permission**: `products.update` or `catalog.manage`

---

## 6. Categories, Brands & Attribute Sets

- `api/v1/catalog/categories` (`GET`, `POST`, `GET {id}`, `PUT {id}`, `DELETE {id}`)
- `api/v1/catalog/brands` (`GET`, `POST`, `GET {id}`, `PUT {id}`, `DELETE {id}`)
- `api/v1/catalog/attributes` (`GET`, `POST`, `GET {id}`, `PUT {id}`, `DELETE {id}`)
- `api/v1/catalog/attribute-sets` (`GET`, `POST`, `GET {id}`, `PUT {id}`, `DELETE {id}`)
