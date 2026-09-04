<?php

declare(strict_types=1);

namespace Modules\Reviews\Contracts;

/**
 * Legitimate cross-module read contract: Catalog/Marketplace's presentation
 * layer calls into this to display rating aggregates without Reviews
 * granting write access to products/vendors, and without Catalog/Marketplace
 * reaching into Reviews' own tables directly.
 */
interface RatingAggregateReaderInterface
{
    /**
     * @return array{average: float, count: int}
     */
    public function forProduct(int $productId): array;

    /**
     * @return array{average: float, count: int}
     */
    public function forVendor(int $vendorId): array;
}
