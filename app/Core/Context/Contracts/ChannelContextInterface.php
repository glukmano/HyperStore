<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface ChannelContextInterface
{
    public function getId(): string|int|null;

    public function getHandle(): ?string;

    public function isResolved(): bool;
}
