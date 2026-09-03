<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\VendorContextInterface;

final readonly class VendorContext implements VendorContextInterface
{
    public function __construct(
        public ?int $id,
        public ?string $uuid
    ) {}

    public static function from(?int $id, ?string $uuid = null): self
    {
        return new self($id, $uuid);
    }

    public static function unresolved(): self
    {
        return new self(null, null);
    }

    public function getVendorId(): ?int
    {
        return $this->id;
    }

    public function getVendorUuid(): ?string
    {
        return $this->uuid;
    }

    public function isResolved(): bool
    {
        return $this->id !== null;
    }
}
