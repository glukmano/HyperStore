<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface CurrencyResolverInterface
{
    public function resolve(): CurrencyContextInterface;
}
