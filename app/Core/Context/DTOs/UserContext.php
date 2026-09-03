<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\UserContextInterface;

final readonly class UserContext implements UserContextInterface
{
    public function __construct(
        private ?int $id = null,
        private ?string $email = null,
    ) {}

    public static function authenticated(int $id, string $email): self
    {
        return new self($id, $email);
    }

    public static function from(int $id, string $email): self
    {
        return new self($id, $email);
    }

    public static function guest(): self
    {
        return new self(null, null);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function isAuthenticated(): bool
    {
        return $this->id !== null;
    }
}
