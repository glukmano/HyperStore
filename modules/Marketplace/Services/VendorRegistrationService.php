<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\VendorApprovalPolicyInterface;
use Modules\Marketplace\DTOs\VendorRegistrationDTO;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Enums\VendorVerificationStatus;
use Modules\Marketplace\Exceptions\SlugAlreadyTakenException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorStorefrontProfile;
use Modules\Marketplace\Models\VendorStoreParticipation;
use Modules\Marketplace\Models\VendorUser;
use Modules\Marketplace\ValueObjects\VendorSlug;

final class VendorRegistrationService
{
    public function __construct(
        private readonly VendorApprovalPolicyInterface $approvalPolicy,
        private readonly MarketplaceConcurrencyBarrierInterface $barrier,
    ) {}

    public function registerVendor(VendorRegistrationDTO $dto): Vendor
    {
        $normalizedSlug = VendorSlug::from($dto->platformSlug)->value();

        return DB::transaction(function () use ($dto, $normalizedSlug): Vendor {
            // Check global platform slug uniqueness under advisory lock / check
            if (Vendor::withoutGlobalScopes()->where('platform_slug', $normalizedSlug)->exists()) {
                throw SlugAlreadyTakenException::forSlug($normalizedSlug);
            }

            $this->barrier->wait('vendor_registration_slug_check');

            $plan = VendorPlan::findOrFail($dto->vendorPlanId);

            /** @var Vendor $vendor */
            $vendor = Vendor::create([
                'tenant_id' => $dto->tenantId,
                'default_store_id' => $dto->defaultStoreId,
                'vendor_plan_id' => $plan->id,
                'name' => $dto->name,
                'platform_slug' => $normalizedSlug,
                'legal_name' => $dto->legalName,
                'tax_id' => $dto->taxId,
                'email' => $dto->email,
                'phone' => $dto->phone,
                'operational_status' => VendorOperationalStatus::Draft,
                'verification_status' => VendorVerificationStatus::Unverified,
                'payout_currency' => $dto->payoutCurrency,
                'submitted_at' => CarbonImmutable::now(),
            ]);

            // Bootstrap exactly-one active owner atomically
            VendorUser::create([
                'tenant_id' => $dto->tenantId,
                'vendor_id' => $vendor->id,
                'user_id' => $dto->ownerUserId,
                'role' => VendorRole::Owner,
                'is_active' => true,
            ]);

            // Create storefront profile
            VendorStorefrontProfile::create([
                'tenant_id' => $dto->tenantId,
                'vendor_id' => $vendor->id,
                'display_name' => $dto->name,
            ]);

            // If default store provided, create participation
            if ($dto->defaultStoreId !== null) {
                VendorStoreParticipation::create([
                    'tenant_id' => $dto->tenantId,
                    'vendor_id' => $vendor->id,
                    'store_id' => $dto->defaultStoreId,
                    'is_enabled' => true,
                ]);
            }

            // Auto-approval evaluation
            if ($this->approvalPolicy->canAutoApprove($vendor)) {
                $vendor->operational_status = VendorOperationalStatus::Active;
                $vendor->approved_at = CarbonImmutable::now();
                $vendor->save();
            }

            return $vendor;
        });
    }
}
