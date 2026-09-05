<?php

declare(strict_types=1);

use App\Core\Localization\Enums\Direction;
use App\Core\Localization\LocaleManager;
use App\Core\ReferenceData\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// ── Direction is DB-driven (Owner Delta §2) — no hardcoded RTL list ──────────

test('LocaleManager direction comes from the registered Language row, not a hardcoded list', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
    Language::create(['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'is_default' => false, 'is_active' => true]);

    $manager = new LocaleManager(app());
    $manager->setLocale('ar');

    expect($manager->isRtl())->toBeTrue();
    expect($manager->getDirection())->toBe(Direction::RTL);
});

test('a made-up Locale never registered is not guessed as RTL from a hardcoded list', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);

    $manager = new LocaleManager(app());
    // "xx" is not Arabic/Hebrew/etc, and is not registered — with no
    // hardcoded RTL_LOCALES list left anywhere, this can only resolve via
    // the platform default Language's own direction.
    $manager->setLocale('xx');

    expect($manager->getDirection())->toBe(Direction::LTR);
});

test('registering a new RTL Locale via the DB immediately changes its resolved direction — no code change', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
    // Hebrew was one of the old hardcoded list's entries — proving this
    // now depends purely on a DB row, register it with LTR direction to
    // show the DB, not any leftover hardcoded assumption, wins.
    Language::create(['code' => 'he', 'name' => 'Hebrew', 'native_name' => 'עברית', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

    $manager = new LocaleManager(app());
    $manager->setLocale('he');

    expect($manager->getDirection())->toBe(Direction::LTR);
});

test('absolute bootstrap failure (zero Language rows) falls back to LTR, never a fatal error', function () {
    $manager = new LocaleManager(app());
    $manager->setLocale('ar');

    expect($manager->getDirection())->toBe(Direction::LTR);
});

test('Direction enum has correct string values', function () {
    expect(Direction::LTR->value)->toBe('ltr');
    expect(Direction::RTL->value)->toBe('rtl');
});

// ── LocaleManager ─────────────────────────────────────────────────────────────

test('LocaleManager defaults to app locale', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
    app()->setLocale('en');
    $manager = new LocaleManager(app());

    expect($manager->getLocale())->toBe('en');
    expect($manager->isRtl())->toBeFalse();
    expect($manager->getDirection())->toBe(Direction::LTR);
});

test('LocaleManager setLocale propagates to Laravel app locale', function () {
    Language::create(['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'is_default' => true, 'is_active' => true]);
    $manager = new LocaleManager(app());
    $manager->setLocale('ar');

    expect(app()->getLocale())->toBe('ar');
});

test('LocaleManager returns supported locales from the Language table, not config', function () {
    Cache::forget(LocaleManager::ACTIVE_LOCALES_CACHE_KEY);
    Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);
    Language::create(['code' => 'de-CH', 'name' => 'German (Switzerland)', 'native_name' => 'Deutsch (Schweiz)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true, 'sort_order' => 1]);
    Language::create(['code' => 'zz', 'name' => 'Inactive', 'native_name' => 'Inactive', 'direction' => 'ltr', 'is_default' => false, 'is_active' => false, 'sort_order' => 2]);

    $manager = new LocaleManager(app());
    $supported = $manager->getSupportedLocales();

    expect($supported)->toContain('en');
    expect($supported)->toContain('de-CH');
    expect($supported)->not->toContain('zz');
});

// ── Route-level locale switching via ?lang= ───────────────────────────────────

test('control-center responds with 200', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/control-center')->assertOk();
});

test('control-center with ?lang=ar responds with 200', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/control-center?lang=ar')->assertOk();
});

test('/up health check responds with JSON ok', function () {
    $this->getJson('/up')
        ->assertOk()
        ->assertJsonFragment(['status' => 'ok']);
});
