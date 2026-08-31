<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface MarketContextInterface
{
    public function getId(): ?int;

    public function getCode(): ?string;

    public function isResolved(): bool;
}
