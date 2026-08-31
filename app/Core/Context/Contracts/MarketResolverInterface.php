<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface MarketResolverInterface
{
    public function resolve(): MarketContextInterface;
}
