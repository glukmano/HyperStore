<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class TicketProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'ticket';
    }

    public function getName(): string
    {
        return 'Event Ticket';
    }

    public function getDescription(): string
    {
        return 'Concert, conference, or cinema admission pass.';
    }

    public function supportsDownloads(): bool
    {
        return true;
    }

    public function supportsBooking(): bool
    {
        return true;
    }
}
