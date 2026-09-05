<?php

declare(strict_types=1);

namespace App\Core\Context\Services;

use App\Core\Context\Contracts\GeoProviderInterface;
use Illuminate\Http\Request;

/**
 * The default GeoProviderInterface binding: no external/managed geo
 * service is bundled with the platform (§15/§36) — "no detection" is
 * always a valid, deterministic outcome.
 */
final class NullGeoProvider implements GeoProviderInterface
{
    public function resolveCountry(Request $request): ?string
    {
        return null;
    }
}
