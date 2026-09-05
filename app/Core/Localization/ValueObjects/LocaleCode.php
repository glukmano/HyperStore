<?php

declare(strict_types=1);

namespace App\Core\Localization\ValueObjects;

use InvalidArgumentException;

/**
 * One authoritative BCP-47-lite normalization/validation boundary (Phase-18
 * Owner Delta §1). Supports the four shapes Master/Owner require:
 * language, language-REGION, language-Script, language-Script-REGION —
 * e.g. ar, ar-SY, de-CH, zh-Hans, zh-Hans-CN, sr-Latn-RS. Every
 * locale-bearing column was widened to varchar(35) specifically so this
 * normalizer is never fighting a storage ceiling.
 */
final class LocaleCode
{
    private const string PATTERN = '/^([a-zA-Z]{2,3})(-([a-zA-Z]{4}))?(-([a-zA-Z]{2}|[0-9]{3}))?$/';

    public static function isValid(string $raw): bool
    {
        return preg_match(self::PATTERN, trim($raw)) === 1;
    }

    /**
     * Deterministic casing: language lowercase, Script Title-case,
     * REGION uppercase — the canonical BCP-47 casing convention.
     */
    public static function normalize(string $raw): string
    {
        $trimmed = trim($raw);

        if (preg_match(self::PATTERN, $trimmed, $m) !== 1) {
            throw new InvalidArgumentException("Malformed locale code: [{$raw}].");
        }

        $parts = [strtolower($m[1])];

        if (($m[3] ?? '') !== '') {
            $parts[] = ucfirst(strtolower($m[3]));
        }

        if (($m[5] ?? '') !== '') {
            $parts[] = ctype_digit($m[5]) ? $m[5] : strtoupper($m[5]);
        }

        return implode('-', $parts);
    }

    /**
     * The bare spoken-language subtag ("ar" from "ar-SY"), used only for
     * Control Center grouping and Accept-Language language-family fallback
     * — never for direction/authorization decisions.
     */
    public static function languageSubtag(string $normalized): string
    {
        return explode('-', $normalized)[0];
    }

    /**
     * A route-safe (case-insensitive, unnormalized input allowed) regex for
     * Laravel's Route::where() — normalization happens after the route
     * matches, inside the resolver.
     */
    public static function routePattern(): string
    {
        return '[a-zA-Z]{2,3}(-[a-zA-Z]{4})?(-([a-zA-Z]{2}|[0-9]{3}))?';
    }
}
