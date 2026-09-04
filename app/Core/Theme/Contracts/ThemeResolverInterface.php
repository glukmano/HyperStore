<?php

declare(strict_types=1);

namespace App\Core\Theme\Contracts;

use App\Core\Stores\Models\Store;
use App\Core\Theme\DTOs\ResolvedTheme;

interface ThemeResolverInterface
{
    /**
     * Resolves the active theme for a Store (Owner Delta: Store-aware, never a hardcoded
     * literal). A Store with no explicit selection, or none at all, resolves to 'default'.
     */
    public function resolveForStore(?Store $store): ResolvedTheme;

    /**
     * Resolves an explicit theme name's inheritance chain directly (used by tests and by
     * resolveForStore()). Applies cycle detection, missing-parent handling, bounded max
     * depth, and deterministic fallback to 'default' in every failure mode.
     */
    public function resolveChain(string $themeName): ResolvedTheme;
}
