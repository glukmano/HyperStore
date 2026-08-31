<?php

declare(strict_types=1);

use App\Core\Features\Contracts\FeatureManagerInterface;
use App\Core\Features\FeatureManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function () {
    Feature::flushCache();
});

// ── Container binding ─────────────────────────────────────────────────────────

test('FeatureManagerInterface is bound in the container', function () {
    expect(app(FeatureManagerInterface::class))->toBeInstanceOf(FeatureManager::class);
});

// ── Global feature flags ───────────────────────────────────────────────────────

test('a feature can be activated globally', function () {
    $manager = app(FeatureManagerInterface::class);

    // Define the feature first
    Feature::define('new-checkout', false);
    $manager->activate('new-checkout');

    expect($manager->active('new-checkout'))->toBeTrue();
});

test('a feature can be deactivated globally', function () {
    $manager = app(FeatureManagerInterface::class);

    Feature::define('beta-ui', true);
    $manager->deactivate('beta-ui');

    expect($manager->active('beta-ui'))->toBeFalse();
});

// ── Scoped feature flags ───────────────────────────────────────────────────────

test('a feature can be activated for a specific user scope', function () {
    $manager = app(FeatureManagerInterface::class);
    $user = User::factory()->create();

    Feature::define('dark-mode', false);
    $manager->activate('dark-mode', $user);

    expect($manager->active('dark-mode', $user))->toBeTrue();
    expect($manager->active('dark-mode'))->toBeFalse(); // global still off
});

test('feature::forget removes cached feature state', function () {
    $manager = app(FeatureManagerInterface::class);

    Feature::define('experimental', true);
    expect($manager->active('experimental'))->toBeTrue();

    $manager->forget('experimental');

    // After forgetting, re-resolving should still work (re-evaluates definition)
    Feature::define('experimental', false);
    expect($manager->active('experimental'))->toBeFalse();
});
