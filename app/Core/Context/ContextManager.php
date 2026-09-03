<?php

declare(strict_types=1);

namespace App\Core\Context;

use App\Core\Context\Contracts\ChannelContextInterface;
use App\Core\Context\Contracts\CurrencyContextInterface;
use App\Core\Context\Contracts\LocaleContextInterface;
use App\Core\Context\Contracts\MarketContextInterface;
use App\Core\Context\Contracts\StoreContextInterface;
use App\Core\Context\Contracts\TenantContextInterface;
use App\Core\Context\Contracts\UserContextInterface;
use App\Core\Context\Contracts\VendorContextInterface;
use App\Core\Context\DTOs\ChannelContext;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Context\DTOs\LocaleContext;
use App\Core\Context\DTOs\MarketContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Context\DTOs\UserContext;
use App\Core\Context\DTOs\VendorContext;

/**
 * ContextManager: In-memory request-scoped context container.
 *
 * Holds the current resolved contexts (Tenant, Store, Channel, Market, Locale, Currency, User, Vendor).
 * All contexts default to unresolved — safe null behaviour is guaranteed.
 *
 * Physical tenancy strategy (DB-per-tenant / schema / shared) is NOT encoded here.
 * Resolver contracts will set the context via set*() methods.
 *
 * Lifecycle: bound as a scoped singleton per request in the service container.
 */
final class ContextManager
{
    private TenantContextInterface $tenant;

    private StoreContextInterface $store;

    private ChannelContextInterface $channel;

    private MarketContextInterface $market;

    private LocaleContextInterface $locale;

    private CurrencyContextInterface $currency;

    private UserContextInterface $user;

    private VendorContextInterface $vendor;

    public function __construct()
    {
        $this->tenant = TenantContext::unresolved();
        $this->store = StoreContext::unresolved();
        $this->channel = ChannelContext::unresolved();
        $this->market = MarketContext::unresolved();
        $this->locale = LocaleContext::unresolved();
        $this->currency = CurrencyContext::unresolved();
        $this->user = UserContext::guest();
        $this->vendor = VendorContext::unresolved();
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

    // ── Channel ───────────────────────────────────────────────────────────────

    public function getChannel(): ChannelContextInterface
    {
        return $this->channel;
    }

    public function setChannel(ChannelContextInterface $channel): void
    {
        $this->channel = $channel;
    }

    public function hasChannel(): bool
    {
        return $this->channel->isResolved();
    }

    // ── Market ────────────────────────────────────────────────────────────────

    public function getMarket(): MarketContextInterface
    {
        return $this->market;
    }

    public function setMarket(MarketContextInterface $market): void
    {
        $this->market = $market;
    }

    public function hasMarket(): bool
    {
        return $this->market->isResolved();
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

    // ── User ──────────────────────────────────────────────────────────────────

    public function getUser(): UserContextInterface
    {
        return $this->user;
    }

    public function setUser(UserContextInterface $user): void
    {
        $this->user = $user;
    }

    public function hasUser(): bool
    {
        return $this->user->isAuthenticated();
    }

    // ── Vendor ────────────────────────────────────────────────────────────────

    public function getVendor(): VendorContextInterface
    {
        return $this->vendor;
    }

    public function setVendor(VendorContextInterface $vendor): void
    {
        $this->vendor = $vendor;
    }

    public function hasVendor(): bool
    {
        return $this->vendor->isResolved();
    }

    // ── Reset (testing / teardown) ────────────────────────────────────────────

    public function reset(): void
    {
        $this->tenant = TenantContext::unresolved();
        $this->store = StoreContext::unresolved();
        $this->channel = ChannelContext::unresolved();
        $this->market = MarketContext::unresolved();
        $this->locale = LocaleContext::unresolved();
        $this->currency = CurrencyContext::unresolved();
        $this->user = UserContext::guest();
        $this->vendor = VendorContext::unresolved();
    }
}
