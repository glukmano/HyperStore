<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface UserContextInterface
{
    public function getId(): ?int;

    public function getEmail(): ?string;

    public function isAuthenticated(): bool;
}
