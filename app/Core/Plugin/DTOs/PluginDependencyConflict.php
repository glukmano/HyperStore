<?php

declare(strict_types=1);

namespace App\Core\Plugin\DTOs;

final readonly class PluginDependencyConflict
{
    public function __construct(
        public string $packageName,
        public string $sourceALabel,
        public string $sourceAVersion,
        public string $sourceBLabel,
        public string $sourceBVersion,
    ) {}

    public function describe(): string
    {
        return "Package [{$this->packageName}] is required at version [{$this->sourceAVersion}] by {$this->sourceALabel} but at version [{$this->sourceBVersion}] by {$this->sourceBLabel}.";
    }
}
