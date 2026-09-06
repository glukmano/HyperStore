<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Contracts;

use Carbon\CarbonInterface;
use Modules\DigitalDelivery\Enums\EntitlementType;
use Modules\DigitalDelivery\Models\CustomerEntitlement;

interface CustomerEntitlementServiceInterface
{
    /**
     * Idempotent by (source_type, source_uuid) — a duplicate grant attempt
     * for the same OrderItem/Subscription returns the existing row.
     */
    public function grant(
        int $tenantId,
        int $customerProfileId,
        EntitlementType $type,
        string $sourceType,
        string $sourceUuid,
        ?CarbonInterface $expiresAt = null,
        ?int $maxUses = null
    ): CustomerEntitlement;

    public function checkAccess(int $customerProfileId, string $sourceType, string $sourceUuid): bool;

    public function revoke(string $sourceType, string $sourceUuid): void;
}
