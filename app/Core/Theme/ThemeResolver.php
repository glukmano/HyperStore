<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Stores\Models\Store;
use App\Core\Theme\Contracts\ThemeRegistryInterface;
use App\Core\Theme\Contracts\ThemeResolverInterface;
use App\Core\Theme\DTOs\ResolvedTheme;

/**
 * Store-aware theme resolution with a safe, bounded multi-level inheritance chain.
 *
 * Owner Delta (Phase-15, 2026-09-04):
 *   1. Active theme resolution is Store-aware — never a hardcoded 'default' literal in
 *      Storefront Core. A Store's `active_theme` column drives resolution; absence of a
 *      selection (or absence of a Store) resolves to 'default'.
 *   3. Child-theme resolution supports a safe multi-level inheritance chain, not a single
 *      level: cycle detection, missing-parent handling, a bounded maximum depth, and a
 *      deterministic fallback to the built-in 'default' theme in every failure mode.
 *
 * Only `themes/default` ships in Phase-15 — this resolver is exercised against fixture
 * manifests in tests to prove the architecture is future-safe, not against a second
 * real shipped theme.
 */
final class ThemeResolver implements ThemeResolverInterface
{
    public const string FALLBACK_THEME = 'default';

    public const int MAX_INHERITANCE_DEPTH = 5;

    public function __construct(
        private readonly ThemeRegistryInterface $registry,
    ) {}

    public function resolveForStore(?Store $store): ResolvedTheme
    {
        $requested = $store?->active_theme;
        $requested = is_string($requested) && trim($requested) !== '' ? trim($requested) : self::FALLBACK_THEME;

        return $this->resolveChain($requested);
    }

    public function resolveChain(string $themeName): ResolvedTheme
    {
        $chain = [];
        $visited = [];
        $current = $themeName;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_INHERITANCE_DEPTH) {
            if (isset($visited[$current])) {
                // Cycle detected: stop walking, fall through to the deterministic default append below.
                break;
            }

            $manifest = $this->registry->get($current);

            if ($manifest === null) {
                // Missing theme / missing parent: stop walking, fall through to default append.
                break;
            }

            $visited[$current] = true;
            $chain[] = $current;
            $current = $manifest->extends;
            $depth++;
        }

        if (! in_array(self::FALLBACK_THEME, $chain, true) && $this->registry->has(self::FALLBACK_THEME)) {
            $chain[] = self::FALLBACK_THEME;
        }

        if ($chain === []) {
            // Deterministic terminal fallback even when 'default' itself is not registered
            // (should not happen in a correctly booted app, but must never 500).
            $chain[] = self::FALLBACK_THEME;
        }

        $viewPaths = [];
        foreach ($chain as $name) {
            $manifest = $this->registry->get($name);
            if ($manifest !== null) {
                $viewPaths[] = $manifest->path;
            }
        }

        return new ResolvedTheme(
            activeThemeName: $chain[0],
            chain: $chain,
            viewPaths: $viewPaths,
        );
    }
}
