<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use Illuminate\Support\Str;

/**
 * Owner Delta correction §7: visitor identity is a first-party RANDOM token
 * held in a signed, SameSite=Lax cookie — never a browser fingerprint. Only
 * the token's hash is ever persisted server-side (in affiliate_clicks); the
 * plain token itself lives only in the visitor's own cookie.
 */
final class AffiliateVisitorTokenService
{
    public const COOKIE_NAME = 'hs_aff_token';

    /**
     * 1 year — long enough to attribute a delayed purchase, short enough
     * that a stale token does not persist indefinitely.
     */
    public const COOKIE_LIFETIME_MINUTES = 60 * 24 * 365;

    public function mintToken(): string
    {
        return Str::random(48);
    }

    public function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function readTokenFromRequest(): ?string
    {
        $token = request()->cookie(self::COOKIE_NAME);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @return array{token: string, hash: string, is_new: bool}
     */
    public function readOrMintHashedToken(): array
    {
        $existing = $this->readTokenFromRequest();
        if ($existing !== null) {
            return ['token' => $existing, 'hash' => $this->hash($existing), 'is_new' => false];
        }

        $plain = $this->mintToken();

        return ['token' => $plain, 'hash' => $this->hash($plain), 'is_new' => true];
    }
}
