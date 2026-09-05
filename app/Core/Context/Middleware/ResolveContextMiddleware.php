<?php

declare(strict_types=1);

namespace App\Core\Context\Middleware;

use App\Core\Context\ContextManager;
use App\Core\Context\Resolvers\ChannelResolver;
use App\Core\Context\Resolvers\CurrencyResolver;
use App\Core\Context\Resolvers\LocaleResolver;
use App\Core\Context\Resolvers\MarketResolver;
use App\Core\Context\Resolvers\StoreResolver;
use App\Core\Context\Resolvers\TenantResolver;
use App\Core\Context\Resolvers\UserResolver;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Routing\DomainAddressingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveContextMiddleware
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly DomainAddressingService $domainService,
        private readonly LocaleManagerInterface $localeManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantResolver = new TenantResolver($request, $this->domainService);
        $tenantContext = $tenantResolver->resolve();
        $this->contextManager->setTenant($tenantContext);

        $storeResolver = new StoreResolver($request, $this->domainService, $tenantContext);
        $storeContext = $storeResolver->resolve();
        $this->contextManager->setStore($storeContext);

        $channelResolver = new ChannelResolver($request);
        $channelContext = $channelResolver->resolve();
        $this->contextManager->setChannel($channelContext);

        $marketResolver = new MarketResolver($request, $storeContext, $this->domainService);
        $marketContext = $marketResolver->resolve();
        $this->contextManager->setMarket($marketContext);

        $localeResolver = new LocaleResolver($request, $marketContext, $this->localeManager);
        $localeContext = $localeResolver->resolve();
        $this->contextManager->setLocale($localeContext);
        if ($localeContext->getLocale() !== null) {
            $this->localeManager->setLocale($localeContext->getLocale());
        }

        $currencyResolver = new CurrencyResolver($request, $marketContext);
        $currencyContext = $currencyResolver->resolve();
        $this->contextManager->setCurrency($currencyContext);

        $userResolver = new UserResolver($request);
        $userContext = $userResolver->resolve();
        $this->contextManager->setUser($userContext);

        return $next($request);
    }
}
