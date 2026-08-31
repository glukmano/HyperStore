<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class CustomProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'custom';
    }

    public function getName(): string
    {
        return 'Personalized Product';
    }

    public function getDescription(): string
    {
        return 'Made-to-order personalized goods with buyer engravings/text.';
    }

    public function requiresShipping(): bool
    {
        return true;
    }

    public function supportsCustomerInput(): bool
    {
        return true;
    }

    public function supportsCustomization(): bool
    {
        return true;
    }
}
