<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Currency Fallback
    |--------------------------------------------------------------------------
    |
    | One centralized last-resort currency fallback (Phase-18), replacing
    | three independently-hardcoded literals ('CHF' in Shipping/Fulfillment,
    | 'USD' in Pricing/Cart/Storefront/CurrencyResolver, 'EUR' in
    | Marketplace) that existed only because the storefront route group
    | previously had no reliable resolved Currency context at all.
    |
    */

    'default_currency_code' => env('PLATFORM_DEFAULT_CURRENCY_CODE', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Geo-Detection Proxy Boundary
    |--------------------------------------------------------------------------
    |
    | TrustedHeaderGeoProvider only ever honors `geo_country_header` when
    | the request's IP matches one of these trusted CIDR/IP entries — an
    | arbitrary client cannot spoof country detection just because the
    | application happens to be reachable. Empty by default: no proxy is
    | trusted, so the header is never consulted (falls through exactly
    | like NullGeoProvider). Example for a Cloudflare-fronted deployment:
    | set this to Cloudflare's published IP ranges and
    | `geo_country_header` to `CF-IPCountry`.
    |
    */

    'trusted_geo_proxies' => array_filter(explode(',', (string) env('PLATFORM_TRUSTED_GEO_PROXIES', ''))),

    'geo_country_header' => env('PLATFORM_GEO_COUNTRY_HEADER'),

];
