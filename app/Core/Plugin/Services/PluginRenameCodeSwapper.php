<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\Contracts\PluginCodeSwapperInterface;

/**
 * Production implementation: a same-filesystem atomic rename(), matching
 * the "same-filesystem rename()" guarantee documented in ADR-0136.
 */
final class PluginRenameCodeSwapper implements PluginCodeSwapperInterface
{
    public function move(string $from, string $to): bool
    {
        return rename($from, $to);
    }
}
