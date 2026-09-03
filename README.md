# HyperStore

Hyper Commerce Platform — a modular-monolith, multi-tenant, multi-vendor commerce
platform built on Laravel. HyperStore combines a first-party marketplace engine
(vendors, commissions, payouts), a first-party fulfillment and dropshipping
subsystem, and a Core/Module/Plugin architecture designed for a future SaaS
offering, without committing prematurely to unresolved architectural decisions
(e.g. physical multi-tenancy model).

## Technology Stack

- **Backend**: PHP 8.4+, Laravel 13
- **Frontend**: Livewire (UI component layer only — no domain logic), Tailwind CSS 4, daisyUI, Vite
- **Database**: PostgreSQL (relational source of truth)
- **Cache / Queue**: Redis
- **Money**: `brick/money` — minor units only, no binary floating-point currency arithmetic anywhere in the domain layer
- **Testing**: Pest (unit, feature, and real multi-process PostgreSQL concurrency suites)
- **Static analysis**: Larastan / PHPStan (level 8)
- **Code style**: Laravel Pint

## Architecture

HyperStore is a **Modular Monolith**. Business capabilities live in independent
modules under `modules/<ModuleName>/` (Catalog, Pricing, Inventory, Shipping,
Cart, Checkout, Order, Payment, Ledger, Marketplace, Fulfillment, Dropshipping,
Control Center, and more), each exposing its own contracts, services, models,
and migrations. Modules communicate through explicit service contracts rather
than reaching into each other's internals, and controllers stay thin — Livewire
components are a presentation layer, not where domain rules live.

Development proceeds strictly **phase-by-phase** against
[`PROJECT_MASTER_PLAN.md`](PROJECT_MASTER_PLAN.md), the highest-authority
technical document in this repository. Active and completed phases are tracked
in [`docs/phases/`](docs/phases); architectural decisions are recorded as ADRs
in [`docs/decisions/`](docs/decisions).

## Local Development Prerequisites

- PHP 8.4+ with the extensions Laravel 13 requires
- Composer 2
- Node.js 20+ and npm
- PostgreSQL 15+
- Redis

## Installation

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

Configure your PostgreSQL and Redis connection details in `.env`, then run
migrations:

```bash
php artisan migrate
```

Build frontend assets:

```bash
npm run build   # production build
npm run dev     # development watcher
```

## Tests

```bash
# Full suite
php -d memory_limit=1G ./vendor/bin/pest

# Static analysis (Larastan level 8)
php -d memory_limit=1G ./vendor/bin/phpstan analyse -a phpstan-bootstrap.php --level=8

# Code style
./vendor/bin/pint --test
```

Some suites under `tests/Concurrency/` exercise real PostgreSQL behavior —
row-level locking, `CONSTRAINT TRIGGER`s, and genuine multi-process races via
independent PHP worker processes — and require a live PostgreSQL connection
(they self-skip otherwise).

## Current Development Status

HyperStore is under active, phase-gated development and is **not** a
production-ready release. Implementation has progressed through order
management, fulfillment (including hybrid decomposition), the dropshipping
subsystem (supplier routing, purchase orders, invoice reconciliation), and
returns/refund economics, alongside earlier phases covering catalog, pricing,
inventory, shipping, cart/checkout, payments, the marketplace/vendor engine,
and the Control Center. See [`docs/phases/README.md`](docs/phases/README.md)
for the authoritative, up-to-date phase index and status.
