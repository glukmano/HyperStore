<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface ChannelResolverInterface
{
    public function resolve(): ChannelContextInterface;
}
