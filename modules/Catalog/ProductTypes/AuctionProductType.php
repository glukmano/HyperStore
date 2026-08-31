<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class AuctionProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'auction';
    }

    public function getName(): string
    {
        return 'Auction Item';
    }

    public function getDescription(): string
    {
        return 'Bidding-based single or multi-lot item.';
    }

    public function supportsAuction(): bool
    {
        return true;
    }

    public function supportsInventory(): bool
    {
        return true;
    }
}
