<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * Moves a plugin's code directory from one path to another as a single
 * filesystem operation. Extracted behind an interface (rather than calling
 * PHP's rename() directly from PluginLifecycleService) solely so the update
 * atomicity protocol's failure paths (ADR-0136, Owner Delta #5 — migration
 * succeeds but code swap fails) can be exercised deterministically in tests
 * without relying on platform-specific filesystem failure conditions.
 */
interface PluginCodeSwapperInterface
{
    public function move(string $from, string $to): bool;
}
