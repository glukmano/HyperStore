<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class PrintOnDemandProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'print-on-demand';
    }

    public function getName(): string
    {
        return 'Print on Demand';
    }

    public function getDescription(): string
    {
        return 'Apparel and accessories printed on order.';
    }

    public function requiresShipping(): bool
    {
        return true;
    }

    public function supportsCustomerInput(): bool
    {
        return true;
    }
}
