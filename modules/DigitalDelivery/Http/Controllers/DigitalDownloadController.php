<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Customers\Services\CustomerProfileService;
use Modules\DigitalDelivery\Exceptions\DownloadLimitExceededException;
use Modules\DigitalDelivery\Exceptions\EntitlementNotGrantedException;
use Modules\DigitalDelivery\Models\CustomerEntitlement;
use Modules\DigitalDelivery\Models\DigitalAsset;
use Modules\DigitalDelivery\Services\DigitalAssetDeliveryService;
use Modules\Order\Models\OrderItem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * D.37: (1) Laravel's own `signed` route middleware verifies the URL
 * itself — never treated as authorization by itself, so ownership is
 * re-checked below too (mirrors MessageAttachmentController's discipline).
 * (2) (3) DigitalAssetDeliveryService::reserveDownload() locks, re-
 * validates, and reserves the one download attempt atomically — BEFORE any
 * byte streams. (4) only then is the file served from the PRIVATE disk.
 * Raw storage paths/asset ids are never exposed in the URL — only the
 * signed, entitlement-scoped token.
 */
class DigitalDownloadController extends Controller
{
    public function download(string $entitlementUuid, Request $request, DigitalAssetDeliveryService $deliveryService, CustomerProfileService $profileService): StreamedResponse
    {
        /** @var CustomerEntitlement|null $entitlement */
        $entitlement = CustomerEntitlement::where('uuid', $entitlementUuid)->first();
        if ($entitlement === null) {
            abort(404);
        }

        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }

        $profile = $profileService->firstOrCreateFor($user);
        if ($entitlement->customer_profile_id !== $profile->id) {
            abort(403, 'This download does not belong to your account.');
        }

        try {
            $reserved = $deliveryService->reserveDownload(
                $entitlement->tenant_id,
                (int) $entitlement->id,
                hash('sha256', (string) $request->ip()),
                $request->userAgent()
            );
        } catch (EntitlementNotGrantedException $e) {
            abort(403, $e->getMessage());
        } catch (DownloadLimitExceededException $e) {
            abort(403, $e->getMessage());
        }

        /** @var OrderItem|null $orderItem */
        $orderItem = OrderItem::where('id', (int) $reserved->source_uuid)->first();
        if ($orderItem === null) {
            abort(404);
        }

        $snapshot = (array) ($orderItem->entitlement_terms_snapshot ?? []);
        $assetId = $snapshot['digital_asset_id'] ?? null;

        /** @var DigitalAsset|null $asset */
        $asset = $assetId !== null
            ? DigitalAsset::where('id', $assetId)->first()
            : DigitalAsset::where('product_id', $orderItem->product_id)->orderByDesc('version')->first();

        if ($asset === null) {
            abort(404);
        }

        return Storage::disk($asset->disk)->download($asset->path);
    }
}
