<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class PreorderProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'preorder';
    }

    public function getName(): string
    {
        return 'Preorder Product';
    }

    public function getDescription(): string
    {
        return 'Advance purchase before inventory availability.';
    }

    public function requiresShipping(): bool
    {
        return true;
    }

    public function supportsInventory(): bool
    {
        return true;
    }
}
