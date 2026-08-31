<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\CurrencyContextInterface;

final class CurrencyContext implements CurrencyContextInterface
{
    private function __construct(
        private readonly ?string $code, // ISO 4217 e.g. "USD"
        private readonly bool $resolved,
    ) {}

    public static function from(string $code): self
    {
        return new self(strtoupper($code), true);
    }

    public static function unresolved(): self
    {
        return new self(null, false);
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
