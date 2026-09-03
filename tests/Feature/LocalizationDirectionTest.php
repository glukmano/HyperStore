<?php

declare(strict_types=1);

use App\Core\Localization\Enums\Direction;
use App\Core\Localization\LocaleManager;
use App\Models\User;

// ── Direction enum ────────────────────────────────────────────────────────────

test('Direction::fromLocale returns LTR for English', function () {
    expect(Direction::fromLocale('en'))->toBe(Direction::LTR);
    expect(Direction::fromLocale('en-US'))->toBe(Direction::LTR);
    expect(Direction::fromLocale('fr'))->toBe(Direction::LTR);
});

test('Direction::fromLocale returns RTL for Arabic', function () {
    expect(Direction::fromLocale('ar'))->toBe(Direction::RTL);
    expect(Direction::fromLocale('ar-SA'))->toBe(Direction::RTL);
});

test('Direction::fromLocale returns RTL for Hebrew', function () {
    expect(Direction::fromLocale('he'))->toBe(Direction::RTL);
});

test('Direction::fromLocale returns RTL for Farsi', function () {
    expect(Direction::fromLocale('fa'))->toBe(Direction::RTL);
});

test('Direction::fromLocale returns RTL for Urdu', function () {
    expect(Direction::fromLocale('ur'))->toBe(Direction::RTL);
});

test('Direction enum has correct string values', function () {
    expect(Direction::LTR->value)->toBe('ltr');
    expect(Direction::RTL->value)->toBe('rtl');
});

// ── LocaleManager ─────────────────────────────────────────────────────────────

test('LocaleManager defaults to app locale', function () {
    app()->setLocale('en');
    $manager = new LocaleManager(app());

    expect($manager->getLocale())->toBe('en');
    expect($manager->isRtl())->toBeFalse();
    expect($manager->getDirection())->toBe(Direction::LTR);
});

test('LocaleManager setLocale changes locale and direction', function () {
    $manager = new LocaleManager(app());
    $manager->setLocale('ar');

    expect($manager->getLocale())->toBe('ar');
    expect($manager->isRtl())->toBeTrue();
    expect($manager->getDirection())->toBe(Direction::RTL);
});

test('LocaleManager setLocale propagates to Laravel app locale', function () {
    $manager = new LocaleManager(app());
    $manager->setLocale('ar');

    expect(app()->getLocale())->toBe('ar');
});

test('LocaleManager returns supported locales from config', function () {
    $manager = new LocaleManager(app());
    $supported = $manager->getSupportedLocales();

    expect($supported)->toContain('en');
    expect($supported)->toContain('ar');
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
