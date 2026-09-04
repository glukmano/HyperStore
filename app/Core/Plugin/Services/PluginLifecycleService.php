<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Plugin\Contracts\PluginCodeSwapperInterface;
use App\Core\Plugin\DTOs\PluginManifest;
use App\Core\Plugin\DTOs\PluginStagingResult;
use App\Core\Plugin\Exceptions\PluginDependencyConflictException;
use App\Core\Plugin\Exceptions\PluginPackageRejectedException;
use App\Core\Plugin\Exceptions\PluginPermissionsNotApprovedException;
use App\Core\Plugin\Models\Plugin;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\Permission\Models\Permission;

/**
 * Orchestrates the full platform-level plugin lifecycle (Owner Delta #1:
 * platform-level only, no tenant/store scoping). See
 * docs/phases/PHASE-16-PLUGIN-SDK-EXTENSIBILITY-PLATFORM.md §9-§11 and
 * ADR-0136 for the exact update-atomicity protocol implemented here.
 */
final class PluginLifecycleService
{
    public function __construct(
        private readonly PluginZipInstaller $zipInstaller,
        private readonly PluginComposerDependencyChecker $dependencyChecker,
        private readonly AuditManagerInterface $auditManager,
        private readonly PluginCodeSwapperInterface $codeSwapper,
    ) {}

    public function install(string $zipPath): Plugin
    {
        $staging = $this->zipInstaller->stageAndValidate($zipPath, storage_path('app/plugin-staging'));
        $manifest = $staging->manifest;

        if (Plugin::query()->where('plugin_id', $manifest->id)->exists()) {
            $this->removeDirectory($staging->stagingPath);
            throw PluginPackageRejectedException::reason("Plugin [{$manifest->id}] is already installed. Use the update flow instead.");
        }

        $this->assertCompatible($manifest);
        $this->assertVendorAutoloaderPresent($staging->stagingPath, $manifest->id);

        $trustLevel = $this->resolveTrustLevel($staging);

        $finalPath = base_path('plugins/'.$manifest->id);
        if (is_dir($finalPath)) {
            $this->removeDirectory($staging->stagingPath);
            throw PluginPackageRejectedException::reason("A directory already exists at plugins/{$manifest->id} outside of plugin management — refusing to overwrite.");
        }

        if (! rename($staging->stagingPath, $finalPath)) {
            throw PluginPackageRejectedException::reason('Unable to move the validated package into the plugins directory.');
        }

        $plugin = Plugin::create([
            'plugin_id' => $manifest->id,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'status' => Plugin::STATUS_INSTALLED,
            'trust_level' => $trustLevel,
            'manifest_snapshot' => $manifest->toArray(),
            'installed_at' => now(),
        ]);

        $this->auditManager->log(
            event: 'plugin.installed',
            properties: ['plugin_id' => $manifest->id, 'version' => $manifest->version, 'trust_level' => $trustLevel],
            subject: $plugin,
        );

        return $plugin;
    }

    public function approvePermissions(string $pluginId): Plugin
    {
        $plugin = $this->findOrFail($pluginId);

        $plugin->granted_permissions = (array) ($plugin->manifest_snapshot['capabilities'] ?? []);
        $plugin->permissions_approved_at = now();
        $plugin->save();

        $this->auditManager->log(
            event: 'plugin.permissions_approved',
            properties: ['plugin_id' => $pluginId, 'capabilities' => $plugin->granted_permissions],
            subject: $plugin,
        );

        return $plugin->refresh();
    }

    public function enable(string $pluginId, bool $approvePermissions = false): Plugin
    {
        $plugin = $this->findOrFail($pluginId);
        $manifest = PluginManifest::fromArray($plugin->manifest_snapshot);

        $this->assertCompatible($manifest);

        $pluginPath = base_path('plugins/'.$pluginId);

        $conflicts = $this->dependencyChecker->findConflicts($pluginPath, $pluginId);
        if ($conflicts !== []) {
            throw new PluginDependencyConflictException($conflicts);
        }

        $requestedCapabilities = $manifest->capabilities;
        $grantedCapabilities = (array) ($plugin->granted_permissions ?? []);
        $newlyRequested = array_diff($requestedCapabilities, $grantedCapabilities);

        if ($newlyRequested !== []) {
            if (! $approvePermissions) {
                throw PluginPermissionsNotApprovedException::forPlugin($pluginId);
            }
            $plugin = $this->approvePermissions($pluginId);
        }

        $this->seedPermissions($manifest);

        $migrationsPath = 'plugins/'.$pluginId.'/'.$manifest->migrationsPath;
        $batchBefore = (int) (DB::table('migrations')->max('batch') ?? 0);

        try {
            if (File::isDirectory(base_path($migrationsPath))) {
                Artisan::call('migrate', ['--path' => $migrationsPath, '--force' => true]);
            }
        } catch (\Throwable $e) {
            $plugin->status = Plugin::STATUS_FAILED;
            $plugin->failure_reason = 'Migration failed during enable: '.$e->getMessage();
            $plugin->save();

            $this->auditManager->log(
                event: 'plugin.enable_failed',
                properties: ['plugin_id' => $pluginId, 'error' => $e->getMessage()],
                subject: $plugin,
            );

            throw $e;
        }

        $batchAfter = (int) (DB::table('migrations')->max('batch') ?? 0);
        if ($batchAfter > $batchBefore) {
            $plugin->last_migration_batch = $batchAfter;
        }

        $plugin->status = Plugin::STATUS_ENABLED;
        $plugin->enabled_at = now();
        $plugin->consecutive_boot_failures = 0;
        $plugin->failure_reason = null;
        $plugin->save();

        $this->clearRouteCacheIfPresent();

        $this->auditManager->log(
            event: 'plugin.enabled',
            properties: ['plugin_id' => $pluginId, 'version' => $plugin->version],
            subject: $plugin,
        );

        return $plugin->refresh();
    }

    public function disable(string $pluginId): Plugin
    {
        $plugin = $this->findOrFail($pluginId);

        $plugin->status = Plugin::STATUS_DISABLED;
        $plugin->disabled_at = now();
        $plugin->save();

        $this->clearRouteCacheIfPresent();

        $this->auditManager->log(
            event: 'plugin.disabled',
            properties: ['plugin_id' => $pluginId],
            subject: $plugin,
        );

        return $plugin->refresh();
    }

    /**
     * Update atomicity protocol (ADR-0136): old code stays live while the new
     * package is staged/validated/migrated; only a successful code swap
     * publishes the new version. See docs/phases/PHASE-16-...md §11.
     */
    public function update(string $pluginId, string $zipPath): Plugin
    {
        $plugin = $this->findOrFail($pluginId);

        $staging = $this->zipInstaller->stageAndValidate($zipPath, storage_path('app/plugin-staging'));
        $newManifest = $staging->manifest;

        if ($newManifest->id !== $pluginId) {
            $this->removeDirectory($staging->stagingPath);
            throw PluginPackageRejectedException::reason("Package id [{$newManifest->id}] does not match the plugin being updated [{$pluginId}].");
        }

        $this->assertCompatible($newManifest);
        $this->assertVendorAutoloaderPresent($staging->stagingPath, $pluginId);

        $conflicts = $this->dependencyChecker->findConflicts($staging->stagingPath, $pluginId);
        if ($conflicts !== []) {
            $this->removeDirectory($staging->stagingPath);
            throw new PluginDependencyConflictException($conflicts);
        }

        $trustLevel = $this->resolveTrustLevel($staging);
        $livePath = base_path('plugins/'.$pluginId);

        // 1. Run new migrations against the still-old-code-active schema.
        $newMigrationsPath = str_replace(base_path().'/', '', $staging->stagingPath).'/'.$newManifest->migrationsPath;
        $batchBefore = (int) (DB::table('migrations')->max('batch') ?? 0);
        $batchAfter = $batchBefore;

        try {
            if (File::isDirectory(base_path($newMigrationsPath))) {
                Artisan::call('migrate', ['--path' => $newMigrationsPath, '--force' => true]);
            }
            $batchAfter = (int) (DB::table('migrations')->max('batch') ?? 0);
        } catch (\Throwable $e) {
            if ($batchAfter > $batchBefore) {
                $this->attemptScopedRollback($newMigrationsPath, $batchAfter);
            }
            $this->removeDirectory($staging->stagingPath);

            $plugin->failure_reason = 'Update migration failed, staged package discarded, previous version unaffected: '.$e->getMessage();
            $plugin->save();

            $this->auditManager->log(
                event: 'plugin.update_migration_failed',
                properties: ['plugin_id' => $pluginId, 'error' => $e->getMessage()],
                subject: $plugin,
            );

            throw $e;
        }

        // 2. Atomic code swap.
        $backupPath = $livePath.'.rollback-'.now()->format('YmdHis');

        if (! $this->codeSwapper->move($livePath, $backupPath)) {
            if ($batchAfter > $batchBefore) {
                $this->attemptScopedRollback($newMigrationsPath, $batchAfter);
            }
            $this->removeDirectory($staging->stagingPath);
            $abortReason = 'Update aborted: could not move the current plugin code aside for the swap. Previous version left untouched.';
            $plugin->failure_reason = $abortReason;
            $plugin->save();
            throw new RuntimeException($abortReason);
        }

        if (! $this->codeSwapper->move($staging->stagingPath, $livePath)) {
            // Code-swap failed AFTER migrations succeeded — the exact case
            // Owner Delta #5 requires handling explicitly.
            $rolledBack = $batchAfter > $batchBefore
                ? $this->attemptScopedRollback($newMigrationsPath, $batchAfter)
                : true;

            if ($rolledBack && $this->codeSwapper->move($backupPath, $livePath)) {
                // Recovered: old code restored, schema rolled back to match it.
                $recoveredReason = 'Update code-swap failed after migrations succeeded; scoped migration rollback succeeded and the previous version was restored.';
                $plugin->failure_reason = $recoveredReason;
                $plugin->save();

                $this->auditManager->log(
                    event: 'plugin.update_swap_failed_recovered',
                    properties: ['plugin_id' => $pluginId, 'attempted_version' => $newManifest->version],
                    subject: $plugin,
                );

                throw new RuntimeException($recoveredReason);
            }

            // Fail closed: schema/code state cannot be safely reconciled.
            // Never claim the previous version is safely running.
            $unsafeReason = 'Update code-swap failed and scoped migration rollback also failed. The plugin is disabled pending manual recovery — do not assume the previous version is safe to run. Backup code (if any) preserved at: '.$backupPath;
            $plugin->status = Plugin::STATUS_FAILED;
            $plugin->failure_reason = $unsafeReason;
            $plugin->save();

            $this->auditManager->log(
                event: 'plugin.update_failed_unsafe_state',
                properties: ['plugin_id' => $pluginId, 'backup_path' => $backupPath],
                subject: $plugin,
            );

            throw new RuntimeException($unsafeReason);
        }

        // 3. Success: publish the new version only now.
        if ($batchAfter > $batchBefore) {
            $plugin->last_migration_batch = $batchAfter;
        }
        $plugin->name = $newManifest->name;
        $plugin->version = $newManifest->version;
        $plugin->trust_level = $trustLevel;
        $plugin->manifest_snapshot = $newManifest->toArray();
        $plugin->failure_reason = null;
        $plugin->save();

        $this->clearRouteCacheIfPresent();

        $this->auditManager->log(
            event: 'plugin.updated',
            properties: ['plugin_id' => $pluginId, 'version' => $newManifest->version, 'backup_path' => $backupPath],
            subject: $plugin,
        );

        return $plugin->refresh();
    }

    public function uninstall(string $pluginId, bool $purgeData = false): void
    {
        $plugin = $this->findOrFail($pluginId);
        $pluginPath = base_path('plugins/'.$pluginId);
        $manifest = PluginManifest::fromArray($plugin->manifest_snapshot);
        $migrationsPath = 'plugins/'.$pluginId.'/'.$manifest->migrationsPath;

        if ($purgeData && File::isDirectory(base_path($migrationsPath))) {
            Artisan::call('migrate:rollback', ['--path' => $migrationsPath, '--force' => true]);
        }

        if (is_dir($pluginPath)) {
            $this->removeDirectory($pluginPath);
        }

        $this->auditManager->log(
            event: 'plugin.uninstalled',
            properties: ['plugin_id' => $pluginId, 'purge_data' => $purgeData],
            subject: $plugin,
        );

        $plugin->delete();

        $this->clearRouteCacheIfPresent();
    }

    private function attemptScopedRollback(string $migrationsPath, int $batch): bool
    {
        try {
            Artisan::call('migrate:rollback', ['--path' => $migrationsPath, '--batch' => $batch, '--force' => true]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertCompatible(PluginManifest $manifest): void
    {
        $phpVersion = PHP_VERSION;
        if ($manifest->phpCompatibility !== '*' && ! Semver::satisfies($phpVersion, $manifest->phpCompatibility)) {
            throw PluginPackageRejectedException::reason("Plugin requires PHP [{$manifest->phpCompatibility}], running [{$phpVersion}].");
        }

        $platformVersion = (string) config('plugins.platform_version', '1.0.0');
        if ($manifest->platformCompatibility !== '*' && ! Semver::satisfies($platformVersion, $manifest->platformCompatibility)) {
            throw PluginPackageRejectedException::reason("Plugin requires platform [{$manifest->platformCompatibility}], running [{$platformVersion}].");
        }
    }

    private function assertVendorAutoloaderPresent(string $path, string $pluginId): void
    {
        if (! file_exists($path.'/vendor/autoload.php')) {
            $this->removeDirectory($path);
            throw PluginPackageRejectedException::reason("Plugin [{$pluginId}] package does not include a generated vendor/autoload.php.");
        }
    }

    private function resolveTrustLevel(PluginStagingResult $staging): string
    {
        if ($staging->integrity === null) {
            if (! (bool) config('plugins.allow_unsigned', false)) {
                $this->removeDirectory($staging->stagingPath);
                throw PluginPackageRejectedException::reason('Unsigned plugin packages are not allowed by the current trust policy.');
            }

            return Plugin::TRUST_UNVERIFIED;
        }

        return app(PluginSignatureVerifier::class)->trustLevelForKeyId($staging->trustedKeyId);
    }

    private function seedPermissions(PluginManifest $manifest): void
    {
        foreach ($manifest->requestedPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
    }

    private function clearRouteCacheIfPresent(): void
    {
        if (app()->routesAreCached()) {
            Artisan::call('route:clear');
        }
    }

    private function findOrFail(string $pluginId): Plugin
    {
        $plugin = Plugin::query()->where('plugin_id', $pluginId)->first();
        if ($plugin === null) {
            throw new RuntimeException("Plugin [{$pluginId}] is not installed.");
        }

        return $plugin;
    }

    private function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }
}
