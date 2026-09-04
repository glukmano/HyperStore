<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\DTOs\PluginDependencyConflict;
use App\Core\Plugin\Models\Plugin;

/**
 * Fail-closed Composer dependency conflict detection (Owner Delta #3, ADR-0136).
 *
 * Plugin lifecycle never runs `composer install`/`require`. A plugin package
 * ships its own pre-built vendor/autoload.php, loaded verbatim (PluginKernel).
 * Before a plugin is enabled, its `vendor/composer/installed.json` is diffed
 * against the platform root's own installed.json and every other currently
 * enabled plugin's — an exact package-name match at a different version is a
 * hard block, never a warning.
 */
final class PluginComposerDependencyChecker
{
    /**
     * @return list<PluginDependencyConflict>
     */
    public function findConflicts(string $candidatePluginPath, string $candidateLabel): array
    {
        $candidatePackages = $this->readInstalledPackages($candidatePluginPath.'/vendor/composer/installed.json');
        if ($candidatePackages === []) {
            return [];
        }

        $conflicts = [];

        $rootPackages = $this->readInstalledPackages(base_path('vendor/composer/installed.json'));
        $conflicts = array_merge($conflicts, $this->diff($candidatePackages, $rootPackages, $candidateLabel, 'the platform root'));

        $enabledPlugins = Plugin::query()->where('status', Plugin::STATUS_ENABLED)->get();
        foreach ($enabledPlugins as $enabledPlugin) {
            if ($enabledPlugin->plugin_id === $candidateLabel) {
                continue;
            }

            $otherPath = base_path('plugins/'.$enabledPlugin->plugin_id.'/vendor/composer/installed.json');
            $otherPackages = $this->readInstalledPackages($otherPath);
            $conflicts = array_merge(
                $conflicts,
                $this->diff($candidatePackages, $otherPackages, $candidateLabel, "plugin [{$enabledPlugin->plugin_id}]")
            );
        }

        return $conflicts;
    }

    /**
     * @return array<string, string> package name => version
     */
    private function readInstalledPackages(string $installedJsonPath): array
    {
        if (! file_exists($installedJsonPath)) {
            return [];
        }

        $raw = file_get_contents($installedJsonPath);
        if ($raw === false) {
            return [];
        }

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        /** @var array<string, string> $packages */
        $packages = [];
        $entries = is_array($data['packages'] ?? null) ? $data['packages'] : $data;
        foreach ((array) $entries as $entry) {
            if (is_array($entry) && isset($entry['name'], $entry['version'])) {
                $packages[(string) $entry['name']] = (string) $entry['version'];
            }
        }

        return $packages;
    }

    /**
     * @param  array<string, string>  $candidate
     * @param  array<string, string>  $other
     * @return list<PluginDependencyConflict>
     */
    private function diff(array $candidate, array $other, string $candidateLabel, string $otherLabel): array
    {
        $conflicts = [];

        foreach ($candidate as $packageName => $candidateVersion) {
            if (! isset($other[$packageName])) {
                continue;
            }

            if ($other[$packageName] !== $candidateVersion) {
                $conflicts[] = new PluginDependencyConflict(
                    packageName: $packageName,
                    sourceALabel: $candidateLabel,
                    sourceAVersion: $candidateVersion,
                    sourceBLabel: $otherLabel,
                    sourceBVersion: $other[$packageName],
                );
            }
        }

        return $conflicts;
    }
}
