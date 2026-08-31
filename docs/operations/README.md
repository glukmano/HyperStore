# Operations, DevOps & Observability Documentation

This directory contains deployment pipelines, server configurations, backup/restore procedures, queue supervisor configurations (Horizon), and performance monitoring guides (Pulse).

## Operational Standards

1. **Environment Segregation**: Local -> Development -> Staging -> Production.
2. **Git Workflow**: Task -> Branch -> Implementation -> Tests / Static Analysis -> Staging -> Release.
3. **Zero-Downtime Migrations**: Backward-compatible schema evolution with pre-tested rollback plans.
4. **Disaster Recovery**: Automated database backups (`spatie/laravel-backup`) with scheduled verification and documented restore playbooks.
5. **Realtime Observability**: Health checks (`spatie/laravel-health`), Horizon queue oversight, and Pulse performance monitoring.
