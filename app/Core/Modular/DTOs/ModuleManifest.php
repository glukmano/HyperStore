<?php

declare(strict_types=1);

namespace App\Core\Modular\DTOs;

/**
 * Immutable value object representing the parsed contents of a module's module.json manifest file.
 */
final class ModuleManifest
{
    /**
     * @param  array<int, string>  $dependencies
     * @param  array<string, string>  $autoload
     */
    public function __construct(
        public readonly string $name,
        public readonly string $namespace,
        public readonly string $provider,
        public readonly string $description,
        public readonly string $version,
        public readonly bool $enabled,
        public readonly array $dependencies,
        public readonly array $autoload,
    ) {}

    /**
     * Parse a raw decoded JSON array into a ModuleManifest DTO.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            namespace: (string) ($data['namespace'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            version: (string) ($data['version'] ?? '1.0.0'),
            enabled: (bool) ($data['enabled'] ?? true),
            dependencies: array_values(array_map('strval', (array) ($data['dependencies'] ?? []))),
            autoload: array_map('strval', (array) ($data['autoload'] ?? [])),
        );
    }
}
