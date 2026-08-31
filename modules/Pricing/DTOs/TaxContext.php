<?php

declare(strict_types=1);

namespace Modules\Pricing\DTOs;

final class TaxContext
{
    public function __construct(
        public int $tenantId,
        public ?string $countryCode = null,
        public ?string $stateCode = null,
        public ?string $postalCode = null,
        public bool $isTaxInclusive = true,
    ) {}
}
