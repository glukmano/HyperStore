<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class NullProductType extends ProductTypeDefinition
{
    public function __construct(private readonly string $id = 'unknown') {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return 'Unknown Product Type';
    }

    public function getDescription(): string
    {
        return 'Unregistered or disabled product type.';
    }
}
