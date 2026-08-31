# Warehouses Architecture Specification

**Module Namespace**: `Modules\Inventory\Models\Warehouse`  
**Status**: Active Production Subdomain (PHASE-05)

---

## 1. Overview

`Warehouse` represents a physical logistics site or facility:
- Multi-tenant isolation (`tenant_id`)
- Unique identifier code (`code`)
- Address fields (`country_code`, `state_code`, `city`, `postal_code`, `address_line_1`, `address_line_2`)
- Timezone and geographic coordinates (`latitude`, `longitude`)
- Facility type (`owned`, `vendor`, `supplier`, `3pl`, `virtual`, `dropship`)
- Priority and operational status

Physical warehouses link to one or more `inventory_sources`.
