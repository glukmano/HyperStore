<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class LicenseProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'license';
    }

    public function getName(): string
    {
        return 'License / Serial Key';
    }

    public function getDescription(): string
    {
        return 'Software license keys, game codes, and activations.';
    }

    public function supportsLicenseDelivery(): bool
    {
        return true;
    }

    public function supportsInventory(): bool
    {
        return true;
    }
}
