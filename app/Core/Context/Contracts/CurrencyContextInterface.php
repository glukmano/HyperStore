<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface CurrencyContextInterface
{
    public function getCode(): ?string; // ISO 4217 e.g. "USD"

    public function isResolved(): bool;
}
