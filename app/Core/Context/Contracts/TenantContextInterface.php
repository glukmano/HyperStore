<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

/**
 * Represents an active tenant context.
 * Physical tenancy strategy (single DB / schema-per-tenant / DB-per-tenant) is deferred.
 */
interface TenantContextInterface
{
    public function getId(): string|int|null;

    public function getName(): ?string;

    public function isResolved(): bool;
}
