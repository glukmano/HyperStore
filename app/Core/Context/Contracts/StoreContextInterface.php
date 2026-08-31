<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface StoreContextInterface
{
    public function getId(): string|int|null;

    public function getSlug(): ?string;

    public function isResolved(): bool;
}
