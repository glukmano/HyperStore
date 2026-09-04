<?php

declare(strict_types=1);

use App\Core\Plugin\DTOs\PluginPackageIntegrity;
use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginSignatureVerifier;

beforeEach(function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $this->publicKey = sodium_crypto_sign_publickey($keyPair);
    $this->secretKey = sodium_crypto_sign_secretkey($keyPair);

    config(['plugins.trusted_publishers' => ['test-publisher' => base64_encode($this->publicKey)]]);
    config(['plugins.publisher_trust_tiers' => ['test-publisher' => Plugin::TRUST_VERIFIED_THIRD_PARTY]]);

    $this->verifier = new PluginSignatureVerifier;
});

function signedIntegrity(string $secretKey, array $overrides = []): PluginPackageIntegrity
{
    $base = array_merge([
        'plugin_id' => 'acme-plugin',
        'version' => '1.0.0',
        'manifest_sha256' => hash('sha256', '{}'),
        'files' => ['src/Foo.php' => hash('sha256', 'foo')],
    ], $overrides);

    $integrityForPayload = PluginPackageIntegrity::fromArray($base + ['signature_algorithm' => 'ed25519', 'publisher_key_id' => 'test-publisher', 'signature' => '']);
    $payload = $integrityForPayload->canonicalPayloadBytes();
    $signature = base64_encode(sodium_crypto_sign_detached($payload, $secretKey));

    return PluginPackageIntegrity::fromArray($base + ['signature_algorithm' => 'ed25519', 'publisher_key_id' => 'test-publisher', 'signature' => $signature]);
}

test('canonical payload is deterministic regardless of file insertion order', function (): void {
    $a = PluginPackageIntegrity::fromArray([
        'plugin_id' => 'x', 'version' => '1.0.0', 'manifest_sha256' => 'abc',
        'files' => ['b.php' => 'hash-b', 'a.php' => 'hash-a'],
    ]);
    $b = PluginPackageIntegrity::fromArray([
        'plugin_id' => 'x', 'version' => '1.0.0', 'manifest_sha256' => 'abc',
        'files' => ['a.php' => 'hash-a', 'b.php' => 'hash-b'],
    ]);

    expect($a->canonicalPayloadBytes())->toBe($b->canonicalPayloadBytes());
});

test('canonical payload never includes the signature field', function (): void {
    $integrity = signedIntegrity($this->secretKey);

    expect($integrity->canonicalPayloadBytes())->not->toContain($integrity->signatureBase64)
        ->and($integrity->canonicalPayloadBytes())->not->toContain('signature');
});

test('a validly signed payload verifies against the trusted key', function (): void {
    $integrity = signedIntegrity($this->secretKey);

    $result = $this->verifier->verify($integrity);

    expect($result['valid'])->toBeTrue()
        ->and($result['trustedKeyId'])->toBe('test-publisher');
});

test('a signature from an untrusted key fails verification', function (): void {
    $otherKeyPair = sodium_crypto_sign_keypair();
    $integrity = signedIntegrity(sodium_crypto_sign_secretkey($otherKeyPair));

    $result = $this->verifier->verify($integrity);

    expect($result['valid'])->toBeFalse();
});

test('tampering with the signed files map after signing invalidates the signature', function (): void {
    $integrity = signedIntegrity($this->secretKey);

    $tampered = PluginPackageIntegrity::fromArray([
        'plugin_id' => $integrity->pluginId,
        'version' => $integrity->version,
        'manifest_sha256' => $integrity->manifestSha256,
        'files' => ['src/Foo.php' => hash('sha256', 'tampered-content')],
        'signature_algorithm' => $integrity->signatureAlgorithm,
        'publisher_key_id' => $integrity->publisherKeyId,
        'signature' => $integrity->signatureBase64,
    ]);

    $result = $this->verifier->verify($tampered);

    expect($result['valid'])->toBeFalse();
});

test('an empty or malformed signature never verifies', function (): void {
    $integrity = signedIntegrity($this->secretKey, []);
    $broken = PluginPackageIntegrity::fromArray([
        'plugin_id' => $integrity->pluginId,
        'version' => $integrity->version,
        'manifest_sha256' => $integrity->manifestSha256,
        'files' => $integrity->files,
        'signature_algorithm' => $integrity->signatureAlgorithm,
        'publisher_key_id' => $integrity->publisherKeyId,
        'signature' => '',
    ]);

    expect($this->verifier->verify($broken)['valid'])->toBeFalse();
});

test('trust level for an unknown key id is unverified', function (): void {
    expect($this->verifier->trustLevelForKeyId(null))->toBe(Plugin::TRUST_UNVERIFIED);
});

test('trust level for a configured publisher key matches its configured tier', function (): void {
    expect($this->verifier->trustLevelForKeyId('test-publisher'))->toBe(Plugin::TRUST_VERIFIED_THIRD_PARTY);
});

test('trust level for a verifying but unconfigured tier key defaults to verified_third_party', function (): void {
    config(['plugins.trusted_publishers' => ['other-publisher' => base64_encode($this->publicKey)]]);
    config(['plugins.publisher_trust_tiers' => []]);

    expect($this->verifier->trustLevelForKeyId('other-publisher'))->toBe(Plugin::TRUST_VERIFIED_THIRD_PARTY);
});
