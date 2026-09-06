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

    /**
     * Owner Delta §14: a license key is an individually distinct secret,
     * allocated via LicenseKeyPool — a fundamentally different semantic
     * than physical Inventory's fungible stock-decrement model, so this is
     * deliberately false.
     */
    public function supportsInventory(): bool
    {
        return false;
    }
}
