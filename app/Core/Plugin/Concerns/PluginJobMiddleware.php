<?php

declare(strict_types=1);

namespace App\Core\Plugin\Concerns;

use App\Core\Plugin\Models\Plugin;
use Closure;

/**
 * Required queue job middleware for every plugin-authored ShouldQueue job or
 * queued listener. Checks the plugin's enabled state directly against the
 * `plugins` table (not the per-request PluginRegistry, since a queue worker
 * is a separate, possibly long-lived process — its own boot cycle may be
 * stale relative to the latest lifecycle change) at EXECUTION time, and
 * releases the job without running plugin business logic if the plugin has
 * since been disabled. This closes the "already-queued when disabled" race
 * that the per-request registry-rebuild guarantee does not cover on its own.
 *
 * Usage: `public function middleware(): array { return [new PluginJobMiddleware('acme-plugin')]; }`
 */
final readonly class PluginJobMiddleware
{
    public function __construct(private string $pluginId) {}

    public function handle(object $job, Closure $next): void
    {
        $isEnabled = Plugin::query()
            ->where('plugin_id', $this->pluginId)
            ->where('status', Plugin::STATUS_ENABLED)
            ->exists();

        if (! $isEnabled) {
            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return;
        }

        $next($job);
    }
}
