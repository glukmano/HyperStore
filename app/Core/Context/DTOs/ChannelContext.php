<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\ChannelContextInterface;

final readonly class ChannelContext implements ChannelContextInterface
{
    public function __construct(
        private ?int $id = null,
        private ?string $handle = null,
    ) {}

    public static function from(int $id, string $handle): self
    {
        return new self($id, $handle);
    }

    public static function unresolved(): self
    {
        return new self(null, null);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHandle(): ?string
    {
        return $this->handle;
    }

    public function isResolved(): bool
    {
        return $this->id !== null;
    }
}
