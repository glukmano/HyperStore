<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

interface ProductTypeInterface
{
    public function getId(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function requiresShipping(): bool;

    public function supportsInventory(): bool;

    public function supportsVariants(): bool;

    public function supportsDownloads(): bool;

    public function supportsRecurringBilling(): bool;

    public function supportsCustomerInput(): bool;

    public function supportsBooking(): bool;

    public function supportsLicenseDelivery(): bool;

    public function supportsAuction(): bool;

    public function supportsQuote(): bool;

    public function supportsCustomization(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * Storefront section template key this Product Type resolves to, e.g.
     * `theme::sections.product-types.{key}`. Types that don't diverge from the
     * generic capability-driven template return 'default' (Phase-15, Owner Delta §0
     * / plan §10 — additive, no scattered `if product_type === ...` in views).
     */
    public function getStorefrontTemplate(): string;
}
