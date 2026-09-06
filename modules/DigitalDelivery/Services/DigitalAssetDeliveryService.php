<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Services;

use Illuminate\Support\Facades\DB;
use Modules\DigitalDelivery\Exceptions\DownloadLimitExceededException;
use Modules\DigitalDelivery\Exceptions\EntitlementNotGrantedException;
use Modules\DigitalDelivery\Models\CustomerEntitlement;
use Modules\DigitalDelivery\Models\DigitalAccessLog;

/**
 * Owner Delta §13: the sequence is "lock -> check -> reserve (increment)
 * -> commit -> THEN stream" — never "check -> stream -> increment later,"
 * which would let two simultaneous requests both pass a since-stale
 * check. If the actual file transfer subsequently fails, the already-
 * committed reservation is NOT automatically refunded — response
 * completion is never financial/access authority.
 */
final class DigitalAssetDeliveryService
{
    /**
     * @throws EntitlementNotGrantedException
     * @throws DownloadLimitExceededException
     */
    public function reserveDownload(int $tenantId, int $entitlementId, string $ipHash, ?string $userAgent): CustomerEntitlement
    {
        return DB::transaction(function () use ($tenantId, $entitlementId, $ipHash, $userAgent): CustomerEntitlement {
            /** @var CustomerEntitlement|null $entitlement */
            $entitlement = CustomerEntitlement::where('tenant_id', $tenantId)
                ->where('id', $entitlementId)
                ->lockForUpdate()
                ->first();

            if ($entitlement === null || $entitlement->revoked_at !== null || ($entitlement->expires_at !== null && $entitlement->expires_at->isPast())) {
                throw EntitlementNotGrantedException::forToken();
            }

            if ($entitlement->max_uses !== null && $entitlement->used_count >= $entitlement->max_uses) {
                throw DownloadLimitExceededException::forEntitlement((int) $entitlement->id);
            }

            $entitlement->used_count++;
            $entitlement->save();

            DigitalAccessLog::create([
                'tenant_id' => $tenantId,
                'customer_entitlement_id' => $entitlement->id,
                'ip_hash' => $ipHash,
                'user_agent' => $userAgent,
                'accessed_at' => now(),
            ]);

            return $entitlement;
        });
    }
}
