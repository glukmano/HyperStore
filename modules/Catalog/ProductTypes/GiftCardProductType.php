<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class GiftCardProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'gift-card';
    }

    public function getName(): string
    {
        return 'Gift Card';
    }

    public function getDescription(): string
    {
        return 'Digital or physical store value vouchers.';
    }

    public function supportsDownloads(): bool
    {
        return true;
    }
}
