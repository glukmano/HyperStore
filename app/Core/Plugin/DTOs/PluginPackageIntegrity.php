<?php

declare(strict_types=1);

namespace App\Core\Plugin\DTOs;

/**
 * Parsed `plugin.sig` — the "HyperStore Plugin Package v1" canonical payload
 * plus its detached signature. See docs/plugins/security-trust-model.md.
 */
final readonly class PluginPackageIntegrity
{
    /**
     * @param  array<string, string>  $files  relative_path => sha256 hex, sorted ascending by key
     */
    public function __construct(
        public string $pluginId,
        public string $version,
        public string $manifestSha256,
        public array $files,
        public string $signatureAlgorithm,
        public string $publisherKeyId,
        public string $signatureBase64,
    ) {}

    /**
     * The exact deterministic byte sequence that was signed — the signature
     * field itself is never part of this payload (non-circular, ADR-0134).
     */
    public function canonicalPayloadBytes(): string
    {
        $files = $this->files;
        ksort($files);

        $payload = [
            'plugin_id' => $this->pluginId,
            'version' => $this->version,
            'manifest_sha256' => $this->manifestSha256,
            'files' => $files,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $files */
        $files = [];
        if (is_array($data['files'] ?? null)) {
            foreach ($data['files'] as $path => $hash) {
                $files[(string) $path] = (string) $hash;
            }
        }
        ksort($files);

        return new self(
            pluginId: (string) ($data['plugin_id'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            manifestSha256: (string) ($data['manifest_sha256'] ?? ''),
            files: $files,
            signatureAlgorithm: (string) ($data['signature_algorithm'] ?? ''),
            publisherKeyId: (string) ($data['publisher_key_id'] ?? ''),
            signatureBase64: (string) ($data['signature'] ?? ''),
        );
    }
}
