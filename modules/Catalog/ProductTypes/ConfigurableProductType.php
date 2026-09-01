<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class ConfigurableProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'configurable';
    }

    public function getName(): string
    {
        return 'Configurable Product';
    }

    public function getDescription(): string
    {
        return 'Custom-configured assembly with customer options.';
    }

    public function supportsCustomerInput(): bool
    {
        return true;
    }

    public function requiresShipping(): bool
    {
        return true;
    }

    public function supportsVariants(): bool
    {
        return true;
    }
}
