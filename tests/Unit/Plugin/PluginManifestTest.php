<?php

declare(strict_types=1);

use App\Core\Plugin\DTOs\PluginManifest;

function validManifestArray(array $overrides = []): array
{
    return array_merge([
        'manifest_version' => 1,
        'id' => 'acme-plugin',
        'name' => 'Acme Plugin',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'MIT',
        'compatibility' => ['platform' => '*', 'php' => '^8.4'],
        'dependencies' => [],
        'requested_permissions' => ['plugin.acme-plugin.view'],
        'capabilities' => ['control_center_navigation'],
        'entrypoint' => 'Plugins\\AcmePlugin\\AcmePluginServiceProvider',
        'namespace' => 'Plugins\\AcmePlugin',
        'migrations' => 'database/migrations',
    ], $overrides);
}

test('parses a valid manifest', function (): void {
    $manifest = PluginManifest::fromArray(validManifestArray());

    expect($manifest->id)->toBe('acme-plugin')
        ->and($manifest->namespace)->toBe('Plugins\\AcmePlugin\\')
        ->and($manifest->entrypoint)->toBe('Plugins\\AcmePlugin\\AcmePluginServiceProvider');
});

test('rejects a missing required field', function (): void {
    $data = validManifestArray();
    unset($data['author']);

    expect(fn () => PluginManifest::fromArray($data))->toThrow(InvalidArgumentException::class);
});

test('rejects an unsupported manifest_version', function (): void {
    expect(fn () => PluginManifest::fromArray(validManifestArray(['manifest_version' => 99])))
        ->toThrow(InvalidArgumentException::class, 'Unsupported plugin manifest_version');
});

test('rejects an id with path traversal characters', function (): void {
    expect(fn () => PluginManifest::fromArray(validManifestArray(['id' => '../evil'])))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects an id with uppercase or invalid slug characters', function (): void {
    expect(fn () => PluginManifest::fromArray(validManifestArray(['id' => 'Acme_Plugin'])))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects the App namespace prefix', function (): void {
    $data = validManifestArray(['namespace' => 'App\\Evil', 'entrypoint' => 'App\\Evil\\EvilServiceProvider']);

    expect(fn () => PluginManifest::fromArray($data))->toThrow(InvalidArgumentException::class, 'reserved platform prefix');
});

test('rejects the Modules namespace prefix', function (): void {
    $data = validManifestArray(['namespace' => 'Modules\\Evil', 'entrypoint' => 'Modules\\Evil\\EvilServiceProvider']);

    expect(fn () => PluginManifest::fromArray($data))->toThrow(InvalidArgumentException::class, 'reserved platform prefix');
});

test('rejects the Illuminate namespace prefix', function (): void {
    $data = validManifestArray(['namespace' => 'Illuminate\\Evil', 'entrypoint' => 'Illuminate\\Evil\\EvilServiceProvider']);

    expect(fn () => PluginManifest::fromArray($data))->toThrow(InvalidArgumentException::class, 'reserved platform prefix');
});

test('rejects the Laravel namespace prefix', function (): void {
    $data = validManifestArray(['namespace' => 'Laravel\\Evil', 'entrypoint' => 'Laravel\\Evil\\EvilServiceProvider']);

    expect(fn () => PluginManifest::fromArray($data))->toThrow(InvalidArgumentException::class, 'reserved platform prefix');
});

test('rejects an entrypoint outside the declared namespace', function (): void {
    $data = validManifestArray(['entrypoint' => 'Plugins\\SomeoneElse\\ServiceProvider']);

    expect(fn () => PluginManifest::fromArray($data))->toThrow(InvalidArgumentException::class, 'own declared namespace');
});

test('rejects a requested permission not namespaced under plugin.<id>.', function (): void {
    $data = validManifestArray(['requested_permissions' => ['orders.manage']]);

    expect(fn () => PluginManifest::fromArray($data))->toThrow(InvalidArgumentException::class, 'must be namespaced');
});

test('round-trips through toArray and fromArray', function (): void {
    $manifest = PluginManifest::fromArray(validManifestArray());
    $roundTripped = PluginManifest::fromArray($manifest->toArray());

    expect($roundTripped->id)->toBe($manifest->id)
        ->and($roundTripped->version)->toBe($manifest->version)
        ->and($roundTripped->requestedPermissions)->toBe($manifest->requestedPermissions);
});
