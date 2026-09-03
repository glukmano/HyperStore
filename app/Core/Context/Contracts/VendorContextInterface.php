<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface VendorContextInterface
{
    public function getVendorId(): ?int;

    public function getVendorUuid(): ?string;

    public function isResolved(): bool;
}
