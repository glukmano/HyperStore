<?php

declare(strict_types=1);

namespace App\Core\Theme\Http\Middleware;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\ChannelContext;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Context\DTOs\MarketContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Routing\DomainAddressingService;
use App\Core\Theme\Contracts\ThemeResolverInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the requesting Store from the host, then resolves and registers that Store's
 * active theme's view-path chain (Owner Delta §0.1/§0.3) so `theme::pages.*` / `theme::sections.*`
 * / `theme::layouts.*` Blade views resolve against the correct child→parent→default chain.
 */
final class ResolveStorefrontThemeMiddleware
{
    public function __construct(
        private readonly DomainAddressingService $domainAddressingService,
        private readonly ThemeResolverInterface $themeResolver,
        private readonly ContextManager $contextManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->domainAddressingService->findStoreByHost($request->getHost());

        if ($store !== null) {
            $this->contextManager->setStore(StoreContext::from($store->id, $store->slug));
            $this->contextManager->setTenant(TenantContext::from($store->tenant_id));

            $market = $store->defaultMarket();
            if ($market !== null) {
                $this->contextManager->setMarket(MarketContext::from($market->id, $market->code));
                $this->contextManager->setCurrency(CurrencyContext::from($market->default_currency_code));
            }

            $channel = $store->defaultChannel();
            if ($channel !== null) {
                $this->contextManager->setChannel(ChannelContext::from($channel->id, $channel->handle));
            }
        }

        $resolved = $this->themeResolver->resolveForStore($store);

        View::replaceNamespace('theme', $resolved->viewPaths);
        View::share('activeThemeName', $resolved->activeThemeName);
        View::share('resolvedThemeChain', $resolved->chain);

        return $next($request);
    }
}
