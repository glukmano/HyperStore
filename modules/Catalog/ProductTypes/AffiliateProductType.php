<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class AffiliateProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'affiliate';
    }

    public function getName(): string
    {
        return 'Affiliate / External Product';
    }

    public function getDescription(): string
    {
        return 'Catalog link referring to external merchant.';
    }
}
