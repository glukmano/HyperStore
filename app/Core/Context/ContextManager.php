<?php

declare(strict_types=1);

namespace App\Core\Context;

use App\Core\Context\Contracts\CurrencyContextInterface;
use App\Core\Context\Contracts\LocaleContextInterface;
use App\Core\Context\Contracts\StoreContextInterface;
use App\Core\Context\Contracts\TenantContextInterface;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Context\DTOs\LocaleContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;

/**
 * ContextManager: In-memory request-scoped context container.
 *
 * Holds the current resolved contexts (Tenant, Store, Locale, Currency).
 * All contexts default to unresolved — safe null behaviour is guaranteed.
 *
 * Physical tenancy strategy (DB-per-tenant / schema / shared) is NOT encoded here.
 * Resolver contracts will set the context via set*() methods in later phases.
 *
 * Lifecycle: bound as a scoped singleton per request in the service container.
 */
final class ContextManager
{
    private TenantContextInterface $tenant;

    private StoreContextInterface $store;

    private LocaleContextInterface $locale;

    private CurrencyContextInterface $currency;

    public function __construct()
    {
        $this->tenant = TenantContext::unresolved();
        $this->store = StoreContext::unresolved();
        $this->locale = LocaleContext::unresolved();
        $this->currency = CurrencyContext::unresolved();
    }

    // ── Tenant ────────────────────────────────────────────────────────────────

    public function getTenant(): TenantContextInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantContextInterface $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function hasTenant(): bool
    {
        return $this->tenant->isResolved();
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function getStore(): StoreContextInterface
    {
        return $this->store;
    }

    public function setStore(StoreContextInterface $store): void
    {
        $this->store = $store;
    }

    public function hasStore(): bool
    {
        return $this->store->isResolved();
    }

    // ── Locale ────────────────────────────────────────────────────────────────

    public function getLocale(): LocaleContextInterface
    {
        return $this->locale;
    }

    public function setLocale(LocaleContextInterface $locale): void
    {
        $this->locale = $locale;
    }

    public function hasLocale(): bool
    {
        return $this->locale->isResolved();
    }

    // ── Currency ──────────────────────────────────────────────────────────────

    public function getCurrency(): CurrencyContextInterface
    {
        return $this->currency;
    }

    public function setCurrency(CurrencyContextInterface $currency): void
    {
        $this->currency = $currency;
    }

    public function hasCurrency(): bool
    {
        return $this->currency->isResolved();
    }

    // ── Reset (testing / teardown) ────────────────────────────────────────────

    public function reset(): void
    {
        $this->tenant = TenantContext::unresolved();
        $this->store = StoreContext::unresolved();
        $this->locale = LocaleContext::unresolved();
        $this->currency = CurrencyContext::unresolved();
    }
}
