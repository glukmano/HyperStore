<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Exceptions\VendorNotFoundException;
use Modules\Marketplace\Exceptions\VendorOwnerInvariantViolationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorUser;

final class VendorOwnershipService
{
    public function __construct(
        private readonly MarketplaceConcurrencyBarrierInterface $barrier,
    ) {}

    public function transferOwnership(int $tenantId, int $vendorId, int $newOwnerUserId): VendorUser
    {
        return DB::transaction(function () use ($tenantId, $vendorId, $newOwnerUserId): VendorUser {
            // 1. Lock Vendor aggregate row
            /** @var Vendor|null $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->find($vendorId);
            if ($vendor === null) {
                throw new VendorNotFoundException("Vendor {$vendorId} not found for tenant {$tenantId}.");
            }

            $this->barrier->wait('ownership_transfer_vendor_locked');

            // 2. Lock current active owner
            /** @var VendorUser|null $currentOwner */
            $currentOwner = VendorUser::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('role', VendorRole::Owner->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($currentOwner !== null && $currentOwner->user_id === $newOwnerUserId) {
                throw VendorOwnerInvariantViolationException::targetUserAlreadyOwner();
            }

            // 3. Demote current owner to manager via direct SQL query under vendor lock
            if ($currentOwner !== null) {
                DB::table('vendor_users')
                    ->where('id', $currentOwner->id)
                    ->update([
                        'role' => VendorRole::Manager->value,
                        'updated_at' => CarbonImmutable::now(),
                    ]);
            }

            // 4. Promote or create new owner
            /** @var VendorUser|null $targetMember */
            $targetMember = VendorUser::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('user_id', $newOwnerUserId)
                ->lockForUpdate()
                ->first();

            if ($targetMember !== null) {
                DB::table('vendor_users')
                    ->where('id', $targetMember->id)
                    ->update([
                        'role' => VendorRole::Owner->value,
                        'is_active' => true,
                        'updated_at' => CarbonImmutable::now(),
                    ]);
                /** @var VendorUser $newOwnerRecord */
                $newOwnerRecord = $targetMember->fresh();
            } else {
                $newOwnerRecord = VendorUser::create([
                    'tenant_id' => $tenantId,
                    'vendor_id' => $vendorId,
                    'user_id' => $newOwnerUserId,
                    'role' => VendorRole::Owner,
                    'is_active' => true,
                ]);
            }

            // 5. Verify exactly one active owner exists
            $activeOwnerCount = VendorUser::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('role', VendorRole::Owner->value)
                ->where('is_active', true)
                ->count();

            if ($activeOwnerCount !== 1) {
                throw VendorOwnerInvariantViolationException::secondOwnerForbidden();
            }

            return $newOwnerRecord;
        });
    }
}
