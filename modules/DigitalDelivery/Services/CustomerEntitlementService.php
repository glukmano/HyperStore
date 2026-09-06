<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\DigitalDelivery\Contracts\CustomerEntitlementServiceInterface;
use Modules\DigitalDelivery\Enums\EntitlementType;
use Modules\DigitalDelivery\Models\CustomerEntitlement;

final class CustomerEntitlementService implements CustomerEntitlementServiceInterface
{
    public function grant(
        int $tenantId,
        int $customerProfileId,
        EntitlementType $type,
        string $sourceType,
        string $sourceUuid,
        ?CarbonInterface $expiresAt = null,
        ?int $maxUses = null
    ): CustomerEntitlement {
        return DB::transaction(function () use ($tenantId, $customerProfileId, $type, $sourceType, $sourceUuid, $expiresAt, $maxUses): CustomerEntitlement {
            /** @var CustomerEntitlement|null $existing */
            $existing = CustomerEntitlement::where('tenant_id', $tenantId)
                ->where('source_type', $sourceType)
                ->where('source_uuid', $sourceUuid)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            return CustomerEntitlement::create([
                'tenant_id' => $tenantId,
                'customer_profile_id' => $customerProfileId,
                'entitlement_type' => $type,
                'source_type' => $sourceType,
                'source_uuid' => $sourceUuid,
                'granted_at' => now(),
                'expires_at' => $expiresAt,
                'max_uses' => $maxUses,
                'used_count' => 0,
            ]);
        });
    }

    public function checkAccess(int $customerProfileId, string $sourceType, string $sourceUuid): bool
    {
        /** @var CustomerEntitlement|null $entitlement */
        $entitlement = CustomerEntitlement::where('customer_profile_id', $customerProfileId)
            ->where('source_type', $sourceType)
            ->where('source_uuid', $sourceUuid)
            ->first();

        return $entitlement !== null && $entitlement->isAccessible();
    }

    public function revoke(string $sourceType, string $sourceUuid): void
    {
        CustomerEntitlement::where('source_type', $sourceType)
            ->where('source_uuid', $sourceUuid)
            ->update(['revoked_at' => now()]);
    }
}
