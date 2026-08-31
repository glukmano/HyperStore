<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class MadeToOrderProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'made-to-order';
    }

    public function getName(): string
    {
        return 'Made to Order';
    }

    public function getDescription(): string
    {
        return 'Custom manufactured on demand item.';
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
