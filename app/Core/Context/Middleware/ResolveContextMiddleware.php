<?php

declare(strict_types=1);

namespace App\Core\Context\Middleware;

use App\Core\Context\ContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveContextMiddleware
 *
 * Phase 01: Delegates to abstract resolver contracts — no concrete routing
 * or database strategy is encoded here. The middleware simply ensures
 * ContextManager is bound and resets to safe unresolved defaults.
 *
 * Real resolvers (hostname, session, header, etc.) will be injected
 * in later phases when the routing/tenancy strategy is decided.
 */
class ResolveContextMiddleware
{
    public function __construct(
        private readonly ContextManager $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Phase 01: Context is unresolved. Resolvers will be injected in Phase 02+.
        // ContextManager already defaults all contexts to unresolved (safe null).
        // Future phases will call:
        //   $this->context->setTenant($tenantResolver->resolve());
        //   $this->context->setStore($storeResolver->resolve());
        //   $this->context->setLocale($localeResolver->resolve());
        //   $this->context->setCurrency($currencyResolver->resolve());

        return $next($request);
    }
}
