<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class RfqProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'rfq';
    }

    public function getName(): string
    {
        return 'Quote / RFQ Product';
    }

    public function getDescription(): string
    {
        return 'Request for Quote enterprise commercial item.';
    }

    public function supportsQuote(): bool
    {
        return true;
    }

    public function supportsCustomerInput(): bool
    {
        return true;
    }
}
