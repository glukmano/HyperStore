<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

use Illuminate\Http\Request;

/**
 * Phase-18 §16: Geo detection is inference only, feeding the lowest-
 * precedence "trusted geo/browser inference" detection tier — never
 * authorization, tax, or legal truth. Returns an ISO 3166-1 alpha-2
 * country code, or null when no detection is possible/configured.
 */
interface GeoProviderInterface
{
    public function resolveCountry(Request $request): ?string;
}
