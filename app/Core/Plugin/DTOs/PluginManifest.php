<?php

declare(strict_types=1);

namespace App\Core\Plugin\DTOs;

use InvalidArgumentException;

/**
 * Parsed, validated `plugin.json`. Immutable value object — see
 * docs/plugins/manifest-reference.md for the authoritative field reference.
 */
final readonly class PluginManifest
{
    public const int SUPPORTED_MANIFEST_VERSION = 1;

    /**
     * Reserved namespace prefixes a plugin may never claim (Owner Delta #3).
     *
     * @var list<string>
     */
    public const array RESERVED_NAMESPACE_PREFIXES = ['App\\', 'Modules\\', 'Illuminate\\', 'Laravel\\'];

    /**
     * @param  array<string, string>  $dependencies
     * @param  list<string>  $requestedPermissions
     * @param  list<string>  $capabilities
     * @param  array<string, array{secret: bool}>  $settingsSchema
     */
    public function __construct(
        public int $manifestVersion,
        public string $id,
        public string $name,
        public string $version,
        public string $author,
        public string $license,
        public ?string $description,
        public string $platformCompatibility,
        public string $phpCompatibility,
        public array $dependencies,
        public array $requestedPermissions,
        public array $capabilities,
        public string $entrypoint,
        public string $namespace,
        public string $migrationsPath,
        public array $settingsSchema,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['manifest_version', 'id', 'name', 'version', 'author', 'license', 'entrypoint', 'namespace'] as $required) {
            if (! isset($data[$required]) || $data[$required] === '') {
                throw new InvalidArgumentException("Plugin manifest is missing required field [{$required}].");
            }
        }

        $manifestVersion = (int) $data['manifest_version'];
        if ($manifestVersion !== self::SUPPORTED_MANIFEST_VERSION) {
            throw new InvalidArgumentException("Unsupported plugin manifest_version [{$manifestVersion}]; this platform supports version [".self::SUPPORTED_MANIFEST_VERSION.'].');
        }

        $id = (string) $data['id'];
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
            throw new InvalidArgumentException("Plugin id [{$id}] must be a lowercase slug (letters, digits, single hyphens), no path separators or \"..\".");
        }

        $namespace = rtrim((string) $data['namespace'], '\\').'\\';
        foreach (self::RESERVED_NAMESPACE_PREFIXES as $reserved) {
            if (str_starts_with($namespace, $reserved)) {
                throw new InvalidArgumentException("Plugin namespace [{$namespace}] claims the reserved platform prefix [{$reserved}].");
            }
        }

        $entrypoint = (string) $data['entrypoint'];
        if (! str_starts_with($entrypoint, $namespace)) {
            throw new InvalidArgumentException("Plugin entrypoint [{$entrypoint}] must belong to the plugin's own declared namespace [{$namespace}].");
        }

        $compatibility = is_array($data['compatibility'] ?? null) ? $data['compatibility'] : [];

        /** @var array<string, string> $dependencies */
        $dependencies = is_array($data['dependencies'] ?? null) ? array_map('strval', $data['dependencies']) : [];

        /** @var list<string> $requestedPermissions */
        $requestedPermissions = is_array($data['requested_permissions'] ?? null)
            ? array_values(array_map('strval', $data['requested_permissions']))
            : [];

        foreach ($requestedPermissions as $permission) {
            if (! str_starts_with($permission, "plugin.{$id}.")) {
                throw new InvalidArgumentException("Requested permission [{$permission}] must be namespaced as [plugin.{$id}.<action>].");
            }
        }

        /** @var list<string> $capabilities */
        $capabilities = is_array($data['capabilities'] ?? null) ? array_values(array_map('strval', $data['capabilities'])) : [];

        /** @var array<string, array{secret: bool}> $settingsSchema */
        $settingsSchema = [];
        if (is_array($data['settings_schema'] ?? null)) {
            foreach ($data['settings_schema'] as $key => $spec) {
                $settingsSchema[(string) $key] = ['secret' => is_array($spec) && (bool) ($spec['secret'] ?? false)];
            }
        }

        return new self(
            manifestVersion: $manifestVersion,
            id: $id,
            name: (string) $data['name'],
            version: (string) $data['version'],
            author: (string) $data['author'],
            license: (string) $data['license'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            platformCompatibility: (string) ($compatibility['platform'] ?? '*'),
            phpCompatibility: (string) ($compatibility['php'] ?? '*'),
            dependencies: $dependencies,
            requestedPermissions: $requestedPermissions,
            capabilities: $capabilities,
            entrypoint: $entrypoint,
            namespace: $namespace,
            migrationsPath: (string) ($data['migrations'] ?? 'database/migrations'),
            settingsSchema: $settingsSchema,
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Plugin manifest must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manifest_version' => $this->manifestVersion,
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'author' => $this->author,
            'license' => $this->license,
            'description' => $this->description,
            'compatibility' => ['platform' => $this->platformCompatibility, 'php' => $this->phpCompatibility],
            'dependencies' => $this->dependencies,
            'requested_permissions' => $this->requestedPermissions,
            'capabilities' => $this->capabilities,
            'entrypoint' => $this->entrypoint,
            'namespace' => rtrim($this->namespace, '\\'),
            'migrations' => $this->migrationsPath,
            'settings_schema' => $this->settingsSchema,
        ];
    }
}
