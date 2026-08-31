<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class DigitalProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'digital';
    }

    public function getName(): string
    {
        return 'Digital Product';
    }

    public function getDescription(): string
    {
        return 'Downloadable files and software assets.';
    }

    public function supportsDownloads(): bool
    {
        return true;
    }
}
