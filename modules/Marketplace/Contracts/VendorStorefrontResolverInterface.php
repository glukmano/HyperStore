<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\DTOs\ResolvedStorefrontDTO;

interface VendorStorefrontResolverInterface
{
    public function resolveByPath(string $vendorSlug, ?int $storeId = null): ResolvedStorefrontDTO;

    public function resolveBySubdomain(string $vendorSlug, ?int $storeId = null): ResolvedStorefrontDTO;

    public function resolveByCustomDomain(string $domain, ?int $storeId = null): ResolvedStorefrontDTO;
}
