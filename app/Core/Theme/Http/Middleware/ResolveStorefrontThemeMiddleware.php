<?php

declare(strict_types=1);

namespace App\Core\Theme\Http\Middleware;

use App\Core\Context\ContextManager;
use App\Core\Stores\Models\Store;
use App\Core\Theme\Contracts\ThemeResolverInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase-18 Owner Delta §3: Context resolves before Theme, always — this
 * middleware runs strictly AFTER ResolveContextMiddleware (see
 * routes/web.php's storefront group ordering) and only ever CONSUMES the
 * already-resolved Store from ContextManager. It must never independently
 * resolve/overwrite Tenant/Store/Market/Currency/Channel — ContextManager
 * is the one authority for request context; ThemeResolver is the one
 * authority for Theme resolution only.
 */
final class ResolveStorefrontThemeMiddleware
{
    public function __construct(
        private readonly ThemeResolverInterface $themeResolver,
        private readonly ContextManager $contextManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $storeId = $this->contextManager->getStore()->getId();
        $store = $storeId !== null ? Store::find($storeId) : null;

        $resolved = $this->themeResolver->resolveForStore($store);

        View::replaceNamespace('theme', $resolved->viewPaths);
        View::share('activeThemeName', $resolved->activeThemeName);
        View::share('resolvedThemeChain', $resolved->chain);

        return $next($request);
    }
}
