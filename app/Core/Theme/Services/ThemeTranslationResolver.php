<?php

declare(strict_types=1);

namespace App\Core\Theme\Services;

use App\Core\Theme\DTOs\ResolvedTheme;
use Illuminate\Support\Facades\Lang;

/**
 * Phase-18 Owner Delta §13: theme translation fallback must be a
 * deterministic, TESTED resolver — not an assumption about how Laravel's
 * namespaced loadTranslationsFrom() hint-merging happens to behave.
 *
 * Precedence, in order:
 *   1. child Theme (resolvedTheme.viewPaths[0]) — reuses ThemeResolver's
 *      already-computed, cycle-safe, bounded-depth chain (no second
 *      inheritance-walking mechanism).
 *   2. each parent Theme in the chain, in order, down to 'default'.
 *   3. the platform's own lang/{locale}/{file}.php catalog.
 *   4. null — the caller (theme_trans()) falls back to the raw key,
 *      exactly like Laravel's own __() convention; never an exception.
 *
 * Theme lang files are read directly from disk rather than through
 * multiple loadTranslationsFrom() namespace hints specifically so the
 * precedence is explicit code, not an implicit framework merge order.
 */
final class ThemeTranslationResolver
{
    public function resolve(string $key, ResolvedTheme $resolvedTheme, string $locale, string $file = 'theme'): ?string
    {
        foreach ($resolvedTheme->viewPaths as $themeRootPath) {
            $langFile = $themeRootPath.'/resources/lang/'.$locale.'/'.$file.'.php';

            if (! is_file($langFile)) {
                continue;
            }

            /** @var mixed $translations */
            $translations = require $langFile;

            if (is_array($translations) && array_key_exists($key, $translations) && is_string($translations[$key])) {
                return $translations[$key];
            }
        }

        $platformKey = "{$file}.{$key}";
        if (Lang::has($platformKey, $locale)) {
            return (string) Lang::get($platformKey, [], $locale);
        }

        return null;
    }
}
