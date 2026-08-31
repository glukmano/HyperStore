# Dependency Registry

This registry tracks all runtime and development dependencies approved and introduced into the **Hyper Commerce Platform**, conforming to [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) Section 6 and Section 28.

Every dependency added via Composer, NPM, or external services MUST be documented in this file before approval.

---

## Approved Target Stack Baseline

| Area | Approved Technology / Target Version | Status | Notes |
| :--- | :--- | :--- | :--- |
| **Runtime** | PHP 8.4+ | Planned Baseline | Target runtime language |
| **Framework** | Laravel 13 | Planned Baseline | Primary application framework |
| **Primary Database** | PostgreSQL 16+ | Planned Baseline | Relational source of truth |
| **Cache & Realtime** | Redis 7+ | Planned Baseline | Ephemeral cache, session, job queues |
| **Frontend SSR** | Blade & Livewire 4 | Planned Baseline | Server-rendered storefront & control center |
| **CSS Framework** | Tailwind CSS 4 & daisyUI 5 | Planned Baseline | Logical utility CSS (RTL/LTR native) |
| **Frontend Tooling** | Vite 6+ / Alpine.js | Planned Baseline | Asset bundler & lightweight UI interactions |
| **Search Engine** | Meilisearch via Laravel Scout | Planned Baseline | Secondary full-text & faceted search |
| **Object Storage** | S3-Compatible / MinIO (Local) | Planned Baseline | Media & digital assets storage |

---

## PHP / Composer Packages Registry

| Package | Target Version | Category | Owning Module / Scope | Reason / Purpose | License | Replacement Strategy |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `laravel/framework` | `^13.0` | Runtime | Core | Core framework | MIT | N/A (Core foundation) |
| `laravel/sanctum` | `*` | Runtime | Core / API | Token-based API authentication | MIT | Laravel Passport if full OAuth2 required |
| `laravel/reverb` | `*` | Runtime | Realtime / Messaging | First-party WebSocket server for Buyer-Seller chat | MIT | Pusher / Soketi |
| `laravel/horizon` | `*` | Runtime | Ops / Queues | Redis queue dashboard and worker management | MIT | Native worker supervisor |
| `laravel/pulse` | `*` | Runtime | Ops / Monitoring | Real-time application performance metrics | MIT | Dedicated APM (Datadog/NewRelic) |
| `laravel/pennant` | `*` | Runtime | Core / Features | Feature flag management | MIT | Custom DB feature flag driver |
| `laravel/scout` | `*` | Runtime | Search | Search driver abstraction | MIT | Direct driver integrations |
| `laravel/socialite` | `*` | Runtime | Auth / Customers | Third-party social authentication | MIT | Custom OAuth client |
| `laravel/ai` | `*` | Runtime | Ai | Laravel AI SDK integration layer | MIT | Custom LLM client abstraction |
| `laravel/mcp` | `*` | Runtime | Ai / MCP | First-party Model Context Protocol server | MIT | Custom JSON-RPC MCP adapter |
| `spatie/laravel-permission` | `*` | Runtime | Core / Auth | Role-based access control (RBAC) | MIT | Custom RBAC engine |
| `spatie/laravel-activitylog` | `*` | Runtime | Core / Audit | Audit logging and activity history | MIT | Custom audit ledger |
| `spatie/laravel-medialibrary` | `*` | Runtime | Media / Catalog | Product and user media associations | MIT | Custom media manager |
| `spatie/laravel-backup` | `*` | Runtime | Ops / Storage | Automated database and file backups | MIT | Cloud-native snapshot tools |
| `spatie/laravel-health` | `*` | Runtime | Ops / Health | Application and infrastructure health checks | MIT | Custom health check controller |
| `spatie/laravel-data` | `*` | Runtime | Core / DTO | Strongly-typed Data Transfer Objects | MIT | Native PHP 8.4 readonly DTOs |
| `spatie/laravel-query-builder` | `*` | Runtime | API / Catalog | Filtering and sorting REST API queries | MIT | Custom query scopes |
| `brick/money` | `*` | Runtime | Money / Ledger | Strict arbitrary-precision monetary calculations | MIT | `ext-bcmath` custom money value objects |
| `pestphp/pest` | `*` | Dev | Testing | Modern testing framework | MIT | PHPUnit |
| `laravel/pint` | `*` | Dev | Tooling | Automated code formatting and style enforcement | MIT | PHP-CS-Fixer |
| `larastan/larastan` | `*` | Dev | Tooling | Static analysis for PHP and Laravel | MIT | PHPStan |

---

## JavaScript / NPM Packages Registry

| Package | Target Version | Category | Owning Scope | Reason / Purpose | License | Replacement Strategy |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `tailwindcss` | `^4.0` | Dev / Runtime | UI / Themes | Utility-first styling framework | MIT | Custom CSS / PostCSS |
| `daisyui` | `^5.0` | Dev / Runtime | UI / Themes | Semantic component classes for Tailwind | MIT | Custom UI component design tokens |
| `vite` | `^6.0` | Dev | Build | Modern frontend asset bundler | MIT | Webpack / Rollup |
| `alpinejs` | `*` | Runtime | UI / Storefront | Lightweight client reactivity for Livewire | MIT | Vanilla JavaScript |

---

## Dependency Ingestion Rules

1. **Phase-Gated Installation**: Packages may only be physically installed when the active phase explicitly mandates them.
2. **License Compatibility**: Only permissive open-source licenses (MIT, Apache-2.0, BSD-2/3-Clause) are permitted without prior architectural review.
3. **No Bloat**: Evaluate whether standard Laravel or native PHP 8.4 features can accomplish the task before adding an external package.
4. **Audit Cleanliness**: Run `composer audit` and `npm audit` during Definition of Done verification.
