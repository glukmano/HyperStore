<?php

declare(strict_types=1);

namespace App\Core\Plugin\DTOs;

final readonly class PluginStagingResult
{
    public function __construct(
        public string $stagingPath,
        public PluginManifest $manifest,
        public ?PluginPackageIntegrity $integrity,
        public bool $signatureValid,
        public ?string $trustedKeyId,
    ) {}
}
