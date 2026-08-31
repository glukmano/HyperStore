<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class VariableProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'variable';
    }

    public function getName(): string
    {
        return 'Variable Product';
    }

    public function getDescription(): string
    {
        return 'Multi-variant matrix product driven by dimension options.';
    }

    public function requiresShipping(): bool
    {
        return true;
    }

    public function supportsInventory(): bool
    {
        return true;
    }

    public function supportsVariants(): bool
    {
        return true;
    }
}
