<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

/**
 * Resolver contracts — Phase 01 only defines the interface contracts.
 * Real implementations (hostname, session, header-based, etc.)
 * will be added in later phases once routing strategy is decided.
 */
interface TenantResolverInterface
{
    public function resolve(): TenantContextInterface;
}
