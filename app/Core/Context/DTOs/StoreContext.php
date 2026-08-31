<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\StoreContextInterface;

final class StoreContext implements StoreContextInterface
{
    private function __construct(
        private readonly string|int|null $id,
        private readonly ?string $slug,
        private readonly bool $resolved,
    ) {}

    public static function from(string|int $id, ?string $slug = null): self
    {
        return new self($id, $slug, true);
    }

    public static function unresolved(): self
    {
        return new self(null, null, false);
    }

    public function getId(): string|int|null
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
