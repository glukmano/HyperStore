<?php

declare(strict_types=1);

namespace Modules\Marketplace\DTOs;

final readonly class VendorRegistrationDTO
{
    public function __construct(
        public int $tenantId,
        public string $name,
        public string $platformSlug,
        public string $legalName,
        public string $email,
        public int $vendorPlanId,
        public int $ownerUserId,
        public ?string $taxId = null,
        public ?string $phone = null,
        public ?int $defaultStoreId = null,
        public string $payoutCurrency = 'EUR',
    ) {}
}
