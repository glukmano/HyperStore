<?php

declare(strict_types=1);

use App\Core\Stores\Models\Store;
use App\Core\Theme\DTOs\ThemeManifest;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeResolver;

/**
 * Proves the Owner Delta (Phase-15, 2026-09-04) requirements for theme resolution:
 * 1. Store-aware resolution — never a hardcoded 'default' literal.
 * 3. Safe multi-level inheritance chain: cycle detection, missing-parent handling,
 *    bounded max depth, deterministic fallback to 'default' in every failure mode.
 */
beforeEach(function (): void {
    $this->registry = new ThemeRegistry;
    $this->resolver = new ThemeResolver($this->registry);

    $this->registry->register(new ThemeManifest(name: 'default', version: '1.0.0', extends: null, path: '/themes/default'));
});

test('resolves default when no store is given', function (): void {
    $resolved = $this->resolver->resolveForStore(null);

    expect($resolved->activeThemeName)->toBe('default')
        ->and($resolved->chain)->toBe(['default'])
        ->and($resolved->viewPaths)->toBe(['/themes/default']);
});

test('resolves the store active_theme, not a hardcoded default', function (): void {
    $this->registry->register(new ThemeManifest(name: 'ocean', version: '1.0.0', extends: 'default', path: '/themes/ocean'));

    $store = new Store(['active_theme' => 'ocean']);

    $resolved = $this->resolver->resolveForStore($store);

    expect($resolved->activeThemeName)->toBe('ocean')
        ->and($resolved->chain)->toBe(['ocean', 'default'])
        ->and($resolved->viewPaths)->toBe(['/themes/ocean', '/themes/default']);
});

test('a store with a blank active_theme falls back to default', function (): void {
    $store = new Store(['active_theme' => '']);

    $resolved = $this->resolver->resolveForStore($store);

    expect($resolved->activeThemeName)->toBe('default');
});

test('resolves a multi-level inheritance chain child to parent to default', function (): void {
    $this->registry->register(new ThemeManifest(name: 'parent', version: '1.0.0', extends: 'default', path: '/themes/parent'));
    $this->registry->register(new ThemeManifest(name: 'child', version: '1.0.0', extends: 'parent', path: '/themes/child'));

    $resolved = $this->resolver->resolveChain('child');

    expect($resolved->chain)->toBe(['child', 'parent', 'default'])
        ->and($resolved->viewPaths)->toBe(['/themes/child', '/themes/parent', '/themes/default']);
});

test('detects a direct cycle and falls back deterministically to default', function (): void {
    $this->registry->register(new ThemeManifest(name: 'a', version: '1.0.0', extends: 'b', path: '/themes/a'));
    $this->registry->register(new ThemeManifest(name: 'b', version: '1.0.0', extends: 'a', path: '/themes/b'));

    $resolved = $this->resolver->resolveChain('a');

    expect($resolved->chain)->toBe(['a', 'b', 'default'])
        ->and($resolved->activeThemeName)->toBe('a');
});

test('detects a self-referencing cycle and still terminates safely', function (): void {
    $this->registry->register(new ThemeManifest(name: 'loopy', version: '1.0.0', extends: 'loopy', path: '/themes/loopy'));

    $resolved = $this->resolver->resolveChain('loopy');

    expect($resolved->chain)->toBe(['loopy', 'default']);
});

test('a missing parent theme stops the chain and falls back to default', function (): void {
    $this->registry->register(new ThemeManifest(name: 'orphan', version: '1.0.0', extends: 'nonexistent-parent', path: '/themes/orphan'));

    $resolved = $this->resolver->resolveChain('orphan');

    expect($resolved->chain)->toBe(['orphan', 'default']);
});

test('an entirely unregistered theme name falls back to default', function (): void {
    $resolved = $this->resolver->resolveChain('does-not-exist');

    expect($resolved->activeThemeName)->toBe('default')
        ->and($resolved->chain)->toBe(['default']);
});

test('enforces a bounded maximum inheritance depth', function (): void {
    // Build a straight-line chain of 8 themes (deeper than MAX_INHERITANCE_DEPTH = 5).
    $names = ['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'];
    foreach ($names as $i => $name) {
        $parent = $names[$i + 1] ?? null;
        $this->registry->register(new ThemeManifest(name: $name, version: '1.0.0', extends: $parent, path: "/themes/{$name}"));
    }

    $resolved = $this->resolver->resolveChain('t1');

    expect(count($resolved->chain))->toBeLessThanOrEqual(ThemeResolver::MAX_INHERITANCE_DEPTH + 1)
        ->and($resolved->chain)->toContain('default');
});

test('never throws regardless of theme graph shape', function (): void {
    $this->registry->register(new ThemeManifest(name: 'x', version: '1.0.0', extends: 'y', path: '/themes/x'));
    $this->registry->register(new ThemeManifest(name: 'y', version: '1.0.0', extends: 'z', path: '/themes/y'));
    $this->registry->register(new ThemeManifest(name: 'z', version: '1.0.0', extends: 'x', path: '/themes/z'));

    expect(fn () => $this->resolver->resolveChain('x'))->not->toThrow(Throwable::class);
});
