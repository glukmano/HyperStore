<?php

declare(strict_types=1);

namespace App\Core\Routing;

/**
 * One deterministic hostname normalization boundary (Phase-18 Owner Delta
 * §6), reused by every domain-owning table's write path and by
 * DomainAddressingService's read path — lowercase, no scheme, no path, no
 * port, no trailing dot.
 */
final class HostnameNormalizer
{
    public static function normalize(string $raw): string
    {
        $host = trim($raw);
        $host = (string) preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $host);
        $host = explode('/', $host, 2)[0];
        $host = explode('?', $host, 2)[0];
        $host = explode('#', $host, 2)[0];

        // Strip a trailing :port, but leave a bracketed IPv6 literal alone.
        if (! str_starts_with($host, '[')) {
            $host = explode(':', $host, 2)[0];
        }

        $host = rtrim($host, '.');

        return strtolower($host);
    }
}
