<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class ServiceProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'service';
    }

    public function getName(): string
    {
        return 'Professional Service';
    }

    public function getDescription(): string
    {
        return 'Consulting, repair, design, and non-tangible services.';
    }

    public function supportsCustomerInput(): bool
    {
        return true;
    }
}
