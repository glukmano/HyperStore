<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\TenantContextInterface;

/**
 * Immutable value object representing a resolved tenant.
 * Use TenantContext::unresolved() when no tenant can be determined.
 */
final class TenantContext implements TenantContextInterface
{
    private function __construct(
        private readonly string|int|null $id,
        private readonly ?string $name,
        private readonly bool $resolved,
    ) {}

    public static function from(string|int $id, ?string $name = null): self
    {
        return new self($id, $name, true);
    }

    public static function unresolved(): self
    {
        return new self(null, null, false);
    }

    public function getId(): string|int|null
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
