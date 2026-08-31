<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface UserResolverInterface
{
    public function resolve(): UserContextInterface;
}
