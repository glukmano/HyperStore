<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * The single, shared reserved-slug list consumed by every slug validator on
 * the platform (Vendor storefront slugs, CMS page slugs, and any future
 * slug-based routing) — a plain, framework-agnostic constant so it can be
 * used from value objects that intentionally stay unit-testable without a
 * booted Laravel application (e.g. Modules\Marketplace\ValueObjects\VendorSlug).
 *
 * Matches PROJECT_MASTER_PLAN.md §11's exact reserved-slug list, extended
 * with the pre-existing platform-operational words VendorSlug already
 * enforced before this list was extracted (Phase-17).
 */
final class ReservedSlugs
{
    /**
     * @var list<string>
     */
    public const array LIST = [
        // Master §11 exact list
        'admin',
        'api',
        'login',
        'register',
        'cart',
        'checkout',
        'account',
        'orders',
        'products',
        'categories',
        'search',
        'support',
        'plugins',
        'themes',
        'system',
        'vendor',
        'seller',
        // Pre-existing VendorSlug operational reservations, retained
        'app',
        'assets',
        'auth',
        'billing',
        'control',
        'dashboard',
        'docs',
        'help',
        'logout',
        'mail',
        'marketplace',
        'payments',
        'platform',
        'portal',
        'root',
        'settings',
        'static',
        'status',
        'store',
        'stores',
        'vendors',
        'webhook',
        'webhooks',
        'www',
    ];
}
