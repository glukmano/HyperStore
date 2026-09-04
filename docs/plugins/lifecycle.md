# Lifecycle

## States

Persisted on `plugins.status` (plain indexed string, not a DB enum type —
matching the existing `ModuleServiceProvider`/seeder conventions in this
codebase), with semantics enforced in `PluginLifecycleService`:

`discovered` → `installed` → `enabled` ⇄ `disabled`, plus `failed` (a
terminal-but-recoverable state reached from a failed enable, a failed update
where rollback also failed, or auto-disable after repeated boot failures —
`update_available`/`incompatible` are reserved status values for future
Control Center surfacing but are not currently written by any lifecycle
method).

```
discovered → (install) → installed → (enable) → enabled ⇄ (disable) → disabled
                                          │
                                    (update, success) → enabled (new version)
                                          │
                                    (update, swap fails, rollback OK) → enabled (old version, recorded reason)
                                          │
                                    (update, swap fails, rollback also fails) → failed
```

## Install

`PluginLifecycleService::install(string $zipPath): Plugin`

1. Stage and validate the ZIP (`PluginZipInstaller::stageAndValidate()`) —
   manifest parse, compatibility check, integrity/signature verification, ZIP
   security checks. See [security-trust-model.md](security-trust-model.md).
2. Reject if a `plugins` row already exists for this `plugin_id` (use
   **update** instead).
3. Reject if `plugins/<id>/` already exists on disk outside plugin
   management (refuses to silently overwrite an unmanaged directory).
4. Reject if the staged package has no generated `vendor/autoload.php`.
5. Resolve trust level from the signature (or fail if unsigned and
   `plugins.allow_unsigned` is false).
6. Atomically move the staged, validated package into `plugins/<id>/`.
7. Create the `plugins` row with `status = 'installed'`.

**Install never auto-enables.** No migrations run, no capabilities are
granted, and the plugin's `register()`/`boot()` are never called until an
explicit, separate `enable`.

## Enable

`PluginLifecycleService::enable(string $pluginId, bool $approvePermissions = false): Plugin`

1. Re-validates compatibility against the current PHP/platform version.
2. Runs `PluginComposerDependencyChecker::findConflicts()` — a same-package
   version collision against any other currently-enabled plugin **hard
   blocks** enable (never just a warning).
3. Diffs the manifest's `capabilities` against `plugins.granted_permissions`
   (the previously-approved set). Any newly requested capability requires
   `$approvePermissions = true` (CLI `--approve-permissions`, or an explicit
   Control Center approval step) — otherwise
   `PluginPermissionsNotApprovedException` is thrown.
4. Seeds the plugin's `requested_permissions` via the existing
   `Permission::firstOrCreate()` pattern.
5. Runs pending migrations scoped to the plugin's own migrations directory
   (`artisan migrate --path=plugins/<id>/database/migrations`), recording the
   resulting migration batch number on `plugins.last_migration_batch` only if
   the batch actually advanced.
6. On migration failure: `status = 'failed'`, failure reason recorded, the
   exception re-thrown — the plugin is never left `enabled` with a partially
   applied schema.
7. On success: `status = 'enabled'`, `enabled_at` set, failure counters
   cleared, route cache cleared if present.

## Disable

`PluginLifecycleService::disable(string $pluginId): Plugin`

Sets `status = 'disabled'`, `disabled_at`. **Never touches data, tables, or
files.** Because `PluginKernel::discover()` only loads plugins whose status
is `enabled`, the disabled plugin's `register()`/`boot()` simply stop being
called on the very next boot cycle — its Navigation/Theme/registry
contributions and routes evaporate for free (see
[overview.md](overview.md#the-per-request-rebuild-invariant)), with no
special cleanup code. Already-queued jobs are caught separately by
`PluginJobMiddleware` at execution time.

Route cache is cleared, matching enable/update/uninstall.

## Update — the atomicity protocol (ADR-0136, Owner Delta #5)

`PluginLifecycleService::update(string $pluginId, string $zipPath): Plugin`

The core guarantee: **the previous working version stays live until the new
version's code has actually and successfully replaced it on disk.** Migration
success alone is never enough to publish a new version.

1. Stage and validate the new package (same pipeline as install), and check
   its `plugin_id` matches the plugin being updated.
2. Re-check compatibility and Composer dependency conflicts against the
   *staged* package.
3. **Run the new package's migrations against the still-old-code-active
   schema**, recording the migration batch before/after
   (`DB::table('migrations')->max('batch')`).
   - If migration fails: any migrations that did apply are rolled back by
     batch number, the staged package is discarded, the previous
     code+manifest are completely untouched, and the original exception is
     re-thrown with a recorded failure reason.
4. **Atomic code swap**, in two steps via `PluginCodeSwapperInterface`
   (production implementation: a same-filesystem `rename()`):
   - Move `plugins/<id>/` aside to `plugins/<id>.rollback-<timestamp>/`.
     - If this move itself fails: any new migrations are rolled back, the
       staged package is discarded, and the update aborts with the previous
       version completely untouched (never even paused).
   - Move the staged, validated package into `plugins/<id>/`.
     - **If this second move fails** (the exact scenario Owner Delta #5
       requires handling): the new migrations are rolled back by batch
       number (scoped `migrate:rollback --path=... --batch=<N>`), and if
       that rollback succeeds, the backup directory is moved back to
       `plugins/<id>/` — the plugin continues running its previous working
       version, with a recorded (non-`failed`) `failure_reason` explaining
       what happened. **If the rollback also fails**, the plugin fails
       closed: `status = 'failed'`, the backup is left on disk (never
       deleted), and the failure reason explicitly states that the previous
       version must not be assumed safe — the service never claims a healthy
       previous version while the schema reflects the new one.
5. Only once the code swap has actually succeeded: the `plugins` row's
   `name`, `version`, `trust_level`, and `manifest_snapshot` are updated to
   the new package's values, and `AuditManagerInterface` records
   `plugin.updated` with the old code's backup path.

This exact sequence — migration succeeds, code swap fails, scoped rollback
recovers the previous version — and the rollback-also-fails fail-closed
branch are both covered by dedicated failure-injection tests in
`tests/Feature/Plugin/PluginUpdateAtomicityTest.php`, using a test double
(`PluginCodeSwapperInterface`) that deterministically fails the "publish
staged code as live" move without relying on platform-specific filesystem
failure conditions.

## Uninstall

`PluginLifecycleService::uninstall(string $pluginId, bool $purgeData = false): void`

- **Plain uninstall** (`purgeData = false`, the default): removes the
  `plugins` row and the `plugins/<id>/` directory. **Plugin-owned database
  tables and their data are left completely intact.**
- **`--purge-data`**: additionally runs a native
  `artisan migrate:rollback --path=plugins/<id>/database/migrations` before
  removing the files — the one explicit, opt-in destructive path in the
  entire lifecycle.

Both paths log `plugin.uninstalled` via `AuditManagerInterface` (with
`purge_data` recorded) and clear the route cache.

## Boot failure isolation and auto-disable

Every `register()`/`boot()` call for every plugin is individually wrapped in
a try/catch inside `PluginKernel`. A thrown exception:

1. Logs `plugin.boot_failed` via `AuditManagerInterface`.
2. Increments `plugins.consecutive_boot_failures`.
3. Once the counter reaches `config('plugins.max_consecutive_boot_failures')`
   (default 3), the plugin is force-transitioned to `disabled` — stopping a
   crash-retry loop on every subsequent request without operator
   intervention.
4. Every other plugin still boots normally in the same cycle — one broken
   plugin never takes down the platform or any other plugin.

CLI recovery: `plugin:disable <id>` and `plugin:enable <id>` both work
against a plugin in any status (including `failed`) — there is no special
"stuck" state that requires manual database surgery to recover from.
