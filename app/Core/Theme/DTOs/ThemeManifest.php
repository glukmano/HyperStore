<?php

declare(strict_types=1);

namespace App\Core\Theme\DTOs;

/**
 * Parsed `theme.json` manifest. Themes live in `themes/<theme-name>/` per ADR-0006.
 */
final readonly class ThemeManifest
{
    public function __construct(
        public string $name,
        public string $version,
        public ?string $extends,
        public string $path,
        /** @var list<string> Product Type template keys this theme provides dedicated sections for. */
        public array $supportedProductTypeTemplates = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path): self
    {
        return new self(
            name: (string) ($data['name'] ?? basename($path)),
            version: (string) ($data['version'] ?? '1.0.0'),
            extends: isset($data['extends']) && is_string($data['extends']) && $data['extends'] !== '' ? $data['extends'] : null,
            path: $path,
            supportedProductTypeTemplates: is_array($data['supported_product_type_templates'] ?? null)
                ? array_values(array_map('strval', $data['supported_product_type_templates']))
                : [],
        );
    }

    public static function fromJsonFile(string $jsonPath): self
    {
        if (! file_exists($jsonPath)) {
            throw new \RuntimeException("Theme manifest not found at [{$jsonPath}].");
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read theme manifest at [{$jsonPath}].");
        }

        $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \RuntimeException("Invalid theme manifest format at [{$jsonPath}].");
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data, dirname($jsonPath));
    }
}
