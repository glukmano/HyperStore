<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface LocaleResolverInterface
{
    public function resolve(): LocaleContextInterface;
}
