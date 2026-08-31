<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

abstract class ProductTypeDefinition implements ProductTypeInterface
{
    public function requiresShipping(): bool
    {
        return false;
    }

    public function supportsInventory(): bool
    {
        return false;
    }

    public function supportsVariants(): bool
    {
        return false;
    }

    public function supportsDownloads(): bool
    {
        return false;
    }

    public function supportsRecurringBilling(): bool
    {
        return false;
    }

    public function supportsCustomerInput(): bool
    {
        return false;
    }

    public function supportsBooking(): bool
    {
        return false;
    }

    public function supportsLicenseDelivery(): bool
    {
        return false;
    }

    public function supportsAuction(): bool
    {
        return false;
    }

    public function supportsQuote(): bool
    {
        return false;
    }

    public function supportsCustomization(): bool
    {
        return false;
    }

    /**
     * @return array<string, bool>
     */
    public function getCapabilities(): array
    {
        return [
            'requiresShipping' => $this->requiresShipping(),
            'supportsInventory' => $this->supportsInventory(),
            'supportsVariants' => $this->supportsVariants(),
            'supportsDownloads' => $this->supportsDownloads(),
            'supportsRecurringBilling' => $this->supportsRecurringBilling(),
            'supportsCustomerInput' => $this->supportsCustomerInput(),
            'supportsBooking' => $this->supportsBooking(),
            'supportsLicenseDelivery' => $this->supportsLicenseDelivery(),
            'supportsAuction' => $this->supportsAuction(),
            'supportsQuote' => $this->supportsQuote(),
            'supportsCustomization' => $this->supportsCustomization(),
        ];
    }
}
