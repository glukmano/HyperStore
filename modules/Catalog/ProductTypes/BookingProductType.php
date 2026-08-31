<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class BookingProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'booking';
    }

    public function getName(): string
    {
        return 'Booking';
    }

    public function getDescription(): string
    {
        return 'Time-slot appointments and ticketed reservations.';
    }

    public function supportsBooking(): bool
    {
        return true;
    }

    public function supportsCustomerInput(): bool
    {
        return true;
    }
}
