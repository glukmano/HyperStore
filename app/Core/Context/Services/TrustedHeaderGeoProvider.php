<?php

declare(strict_types=1);

namespace App\Core\Context\Services;

use App\Core\Context\Contracts\GeoProviderInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Phase-18 Owner Delta §11: honoring a Geo header (e.g. CF-IPCountry) is
 * NOT safe just because a proxy config value is non-null — that alone
 * doesn't prove the CURRENT request actually arrived through the trusted
 * edge. This reuses Symfony's own trusted-proxy CIDR-matching utility
 * (already a framework dependency, no new package) to verify the
 * request's real IP is inside a configured trusted range before the
 * header is ever read. No match → the header is completely ignored,
 * exactly like NullGeoProvider.
 */
final class TrustedHeaderGeoProvider implements GeoProviderInterface
{
    public function resolveCountry(Request $request): ?string
    {
        $trustedProxies = array_values((array) config('platform.trusted_geo_proxies', []));
        $headerName = config('platform.geo_country_header');

        if ($trustedProxies === [] || ! is_string($headerName) || $headerName === '') {
            return null;
        }

        $remoteIp = $request->ip();
        if ($remoteIp === null || ! IpUtils::checkIp($remoteIp, $trustedProxies)) {
            return null;
        }

        $value = $request->headers->get($headerName);
        if (! is_string($value) || $value === '') {
            return null;
        }

        $code = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }
}
