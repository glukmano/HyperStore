<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Services;

use Modules\Customers\Services\CustomerProfileService;
use Modules\DigitalDelivery\Contracts\CustomerEntitlementServiceInterface;
use Modules\DigitalDelivery\Contracts\DigitalEntitlementGrantHookInterface;
use Modules\DigitalDelivery\Enums\EntitlementType;
use Modules\DigitalDelivery\Models\DigitalAsset;
use Modules\Order\Models\Order;

final class DigitalEntitlementGrantHook implements DigitalEntitlementGrantHookInterface
{
    public function __construct(
        private readonly CustomerEntitlementServiceInterface $entitlementService,
        private readonly LicenseKeyAllocationService $licenseAllocation,
        private readonly CustomerProfileService $profileService
    ) {}

    public function grantForOrder(Order $order): void
    {
        if ($order->user_id === null) {
            // Digital/License delivery requires a real Customer identity to
            // receive the entitlement — a guest Order simply has none to
            // grant against, not an error condition.
            return;
        }

        $user = $order->user;
        if ($user === null) {
            return;
        }
        $profile = $this->profileService->firstOrCreateFor($user);

        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $sourceUuid = (string) $item->id;

            if ($item->product_type_snapshot === 'digital') {
                /** @var DigitalAsset|null $asset */
                $asset = DigitalAsset::where('tenant_id', $order->tenant_id)
                    ->where('product_id', $item->product_id)
                    ->orderByDesc('version')
                    ->first();

                $expiresAt = ($asset !== null && $asset->download_expiry_days !== null)
                    ? now()->addDays($asset->download_expiry_days)
                    : null;
                $maxUses = $asset?->max_downloads;

                $this->entitlementService->grant(
                    $order->tenant_id,
                    $profile->id,
                    EntitlementType::DigitalDownload,
                    'order_item',
                    $sourceUuid,
                    $expiresAt,
                    $maxUses
                );

                if ($asset !== null) {
                    $item->entitlement_terms_snapshot = [
                        'digital_asset_id' => $asset->id,
                        'max_downloads' => $asset->max_downloads,
                        'download_expiry_days' => $asset->download_expiry_days,
                    ];
                    $item->save();
                }
            }

            if ($item->product_type_snapshot === 'license') {
                $this->entitlementService->grant(
                    $order->tenant_id,
                    $profile->id,
                    EntitlementType::DigitalDownload,
                    'order_item',
                    $sourceUuid
                );

                $this->licenseAllocation->allocate($order->tenant_id, (int) $item->product_id, (int) $item->id);
            }
        }
    }
}
