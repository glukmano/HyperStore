<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\DTOs\PluginPackageIntegrity;
use App\Core\Plugin\Models\Plugin;

/**
 * Verifies a "HyperStore Plugin Package v1" signature (ADR-0134) using
 * libsodium against the configured trusted-publisher key allowlist.
 */
final class PluginSignatureVerifier
{
    /**
     * @return array{valid: bool, trustedKeyId: ?string}
     */
    public function verify(PluginPackageIntegrity $integrity): array
    {
        $signature = base64_decode($integrity->signatureBase64, strict: true);
        if ($signature === false || $signature === '') {
            return ['valid' => false, 'trustedKeyId' => null];
        }

        $payload = $integrity->canonicalPayloadBytes();

        /** @var array<string, string> $trustedPublishers */
        $trustedPublishers = (array) config('plugins.trusted_publishers', []);

        foreach ($trustedPublishers as $keyId => $publicKeyBase64) {
            $publicKey = base64_decode((string) $publicKeyBase64, strict: true);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return ['valid' => false, 'trustedKeyId' => null];
            }

            if (sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
                return ['valid' => true, 'trustedKeyId' => (string) $keyId];
            }
        }

        return ['valid' => false, 'trustedKeyId' => null];
    }

    public function trustLevelForKeyId(?string $keyId): string
    {
        if ($keyId === null) {
            return Plugin::TRUST_UNVERIFIED;
        }

        /** @var array<string, string> $tiers */
        $tiers = (array) config('plugins.publisher_trust_tiers', []);

        return $tiers[$keyId] ?? Plugin::TRUST_VERIFIED_THIRD_PARTY;
    }
}
