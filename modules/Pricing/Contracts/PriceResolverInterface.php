<?php

declare(strict_types=1);

namespace Modules\Pricing\Contracts;

use Modules\Pricing\DTOs\PriceResult;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;

interface PriceResolverInterface
{
    public function resolve(PricingItem $item, PricingContext $context): ?PriceResult;
}
