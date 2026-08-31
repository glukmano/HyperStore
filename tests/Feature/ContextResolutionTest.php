<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Context\DTOs\LocaleContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;

// ── TenantContext DTO ─────────────────────────────────────────────────────────

test('TenantContext::unresolved() returns unresolved context', function () {
    $ctx = TenantContext::unresolved();

    expect($ctx->isResolved())->toBeFalse();
    expect($ctx->getId())->toBeNull();
    expect($ctx->getName())->toBeNull();
});

test('TenantContext::from() returns resolved context', function () {
    $ctx = TenantContext::from(42, 'Acme Corp');

    expect($ctx->isResolved())->toBeTrue();
    expect($ctx->getId())->toBe(42);
    expect($ctx->getName())->toBe('Acme Corp');
});

// ── StoreContext DTO ──────────────────────────────────────────────────────────

test('StoreContext::unresolved() returns unresolved context', function () {
    $ctx = StoreContext::unresolved();

    expect($ctx->isResolved())->toBeFalse();
    expect($ctx->getId())->toBeNull();
    expect($ctx->getSlug())->toBeNull();
});

test('StoreContext::from() returns resolved context', function () {
    $ctx = StoreContext::from(1, 'main-store');

    expect($ctx->isResolved())->toBeTrue();
    expect($ctx->getId())->toBe(1);
    expect($ctx->getSlug())->toBe('main-store');
});

// ── LocaleContext DTO ─────────────────────────────────────────────────────────

test('LocaleContext::unresolved() returns unresolved context', function () {
    $ctx = LocaleContext::unresolved();

    expect($ctx->isResolved())->toBeFalse();
    expect($ctx->getLocale())->toBeNull();
    expect($ctx->getDirection())->toBeNull();
});

test('LocaleContext::from() returns correct locale and direction', function () {
    $ltr = LocaleContext::from('en', 'ltr');
    expect($ltr->getLocale())->toBe('en');
    expect($ltr->getDirection())->toBe('ltr');

    $rtl = LocaleContext::from('ar', 'rtl');
    expect($rtl->getLocale())->toBe('ar');
    expect($rtl->getDirection())->toBe('rtl');
});

// ── CurrencyContext DTO ───────────────────────────────────────────────────────

test('CurrencyContext::unresolved() returns unresolved context', function () {
    $ctx = CurrencyContext::unresolved();

    expect($ctx->isResolved())->toBeFalse();
    expect($ctx->getCode())->toBeNull();
});

test('CurrencyContext::from() normalizes currency code to uppercase', function () {
    $ctx = CurrencyContext::from('usd');

    expect($ctx->isResolved())->toBeTrue();
    expect($ctx->getCode())->toBe('USD');
});

// ── ContextManager ────────────────────────────────────────────────────────────

test('ContextManager defaults all contexts to unresolved', function () {
    $manager = new ContextManager;

    expect($manager->hasTenant())->toBeFalse();
    expect($manager->hasStore())->toBeFalse();
    expect($manager->hasLocale())->toBeFalse();
    expect($manager->hasCurrency())->toBeFalse();
});

test('ContextManager sets and retrieves tenant', function () {
    $manager = new ContextManager;
    $tenant = TenantContext::from(1, 'Test Tenant');

    $manager->setTenant($tenant);

    expect($manager->hasTenant())->toBeTrue();
    expect($manager->getTenant()->getId())->toBe(1);
    expect($manager->getTenant()->getName())->toBe('Test Tenant');
});

test('ContextManager sets and retrieves store', function () {
    $manager = new ContextManager;
    $store = StoreContext::from(5, 'my-store');

    $manager->setStore($store);

    expect($manager->hasStore())->toBeTrue();
    expect($manager->getStore()->getSlug())->toBe('my-store');
});

test('ContextManager sets and retrieves locale', function () {
    $manager = new ContextManager;
    $locale = LocaleContext::from('ar', 'rtl');

    $manager->setLocale($locale);

    expect($manager->hasLocale())->toBeTrue();
    expect($manager->getLocale()->getLocale())->toBe('ar');
    expect($manager->getLocale()->getDirection())->toBe('rtl');
});

test('ContextManager reset() restores all contexts to unresolved', function () {
    $manager = new ContextManager;
    $manager->setTenant(TenantContext::from(1));
    $manager->setStore(StoreContext::from(1));
    $manager->setLocale(LocaleContext::from('ar', 'rtl'));
    $manager->setCurrency(CurrencyContext::from('SAR'));

    $manager->reset();

    expect($manager->hasTenant())->toBeFalse();
    expect($manager->hasStore())->toBeFalse();
    expect($manager->hasLocale())->toBeFalse();
    expect($manager->hasCurrency())->toBeFalse();
});

test('ContextManager is scoped in container (new instance per test resolution)', function () {
    $ctx1 = app(ContextManager::class);
    $ctx2 = app(ContextManager::class);

    // Scoped = same instance within a single request lifecycle (Laravel scoped() binding)
    expect($ctx1)->toBe($ctx2);
});

test('context isolation: setting tenant in one manager does not affect a fresh manager', function () {
    $managerA = new ContextManager;
    $managerA->setTenant(TenantContext::from(99, 'Tenant A'));

    $managerB = new ContextManager;

    expect($managerB->hasTenant())->toBeFalse();
    expect($managerA->getTenant()->getId())->toBe(99);
});
