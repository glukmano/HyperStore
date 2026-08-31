---
name: performance-observability
description: Enforces query optimization, N+1 prevention, Redis caching boundaries, queue/scheduler health, Laravel Horizon, and Pulse. Use when optimizing queries, caching, queues, or monitoring.
---

# Performance Optimization & Observability

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 26)

## Core Rules & Mandates

1. **Database Query Efficiency**:
   - Strictly prevent **N+1 queries** using eager loading (`with(...)`) and query counters in tests.
   - Enforce database indexing on foreign keys, tenant IDs, timestamps, and search filters.
   - Avoid unbounded queries (`Model::all()`); always use chunking, cursor pagination, or bounded limits.
2. **Caching Hygiene**:
   - Cache expensive aggregations, category trees, and tenant configurations in Redis.
   - Use Redis cache tagging (`Cache::tags(...)`) where supported for surgical invalidation upon model updates.
   - **Never store durable business state solely in Redis cache.**
3. **Queue & Job Management**:
   - Offload heavy tasks (emails, webhooks, search indexing, media processing, AI jobs) to background queues managed by **Laravel Horizon**.
   - Set sensible job timeouts, retries with exponential backoffs, and dead-letter handling for failed jobs.
4. **Monitoring & Health Checks**:
   - Integrate **Laravel Pulse** for application performance monitoring, slow query tracking, and queue metrics.
   - Implement **Spatie Laravel Health** endpoints covering DB connectivity, Redis latency, disk space, and queue throughput.

## Pre-Execution Checklist
- [ ] Are relations eager-loaded to eliminate N+1 query bottlenecks?
- [ ] Are background jobs configured with explicit timeouts and retry backoffs?
- [ ] Is cache invalidation tied to relevant domain events?

## Forbidden Shortcuts
- ❌ Running unpaginated queries on large datasets.
- ❌ Caching data indefinitely without invalidation triggers.
- ❌ Executing slow network calls synchronously in user web requests.

## Validation Steps
1. Assert query counts in feature tests (e.g., `< 10 queries per page render`).
2. Verify Redis cache hit and surgical invalidation upon record update.
3. Check Horizon dashboard and queue throughput under load.
