<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\MarketContextInterface;

final readonly class MarketContext implements MarketContextInterface
{
    public function __construct(
        private ?int $id = null,
        private ?string $code = null,
    ) {}

    public static function from(int $id, string $code): self
    {
        return new self($id, $code);
    }

    public static function unresolved(): self
    {
        return new self(null, null);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function isResolved(): bool
    {
        return $this->id !== null;
    }
}
