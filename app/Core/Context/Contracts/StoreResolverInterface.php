<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface StoreResolverInterface
{
    public function resolve(): StoreContextInterface;
}
