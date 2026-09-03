<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorVerificationStatus;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\PayoutRequest;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorVerification;

final class AdminMarketplaceVendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::with(['plan', 'storefrontProfile', 'users.user']);

        if ($request->has('status')) {
            $query->where('operational_status', $request->query('status'));
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $vendor = Vendor::with(['plan', 'storefrontProfile', 'users.user', 'domains', 'storeParticipations'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'data' => $vendor,
        ]);
    }

    public function approve(string $uuid): JsonResponse
    {
        /** @var Vendor $vendor */
        $vendor = Vendor::where('uuid', $uuid)->firstOrFail();

        if (! $vendor->operational_status->canTransitionTo(VendorOperationalStatus::Active)) {
            throw VendorOperationalStatusException::invalidTransition(
                $vendor->operational_status->value,
                VendorOperationalStatus::Active->value
            );
        }

        $vendor->operational_status = VendorOperationalStatus::Active;
        $vendor->approved_at = CarbonImmutable::now();
        $vendor->save();

        return response()->json([
            'message' => 'Vendor approved successfully.',
            'data' => $vendor,
        ]);
    }

    public function suspend(string $uuid): JsonResponse
    {
        /** @var Vendor $vendor */
        $vendor = Vendor::where('uuid', $uuid)->firstOrFail();

        if (! $vendor->operational_status->canTransitionTo(VendorOperationalStatus::Suspended)) {
            throw VendorOperationalStatusException::invalidTransition(
                $vendor->operational_status->value,
                VendorOperationalStatus::Suspended->value
            );
        }

        $vendor->operational_status = VendorOperationalStatus::Suspended;
        $vendor->suspended_at = CarbonImmutable::now();
        $vendor->save();

        return response()->json([
            'message' => 'Vendor suspended successfully.',
            'data' => $vendor,
        ]);
    }

    public function verify(string $uuid, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:verified,rejected,needs_review',
            'provider_name' => 'nullable|string|max:64',
            'external_reference_id' => 'nullable|string|max:255',
            'rejection_reason_code' => 'nullable|string|max:64',
            'metadata' => 'nullable|array',
        ]);

        /** @var Vendor $vendor */
        $vendor = Vendor::where('uuid', $uuid)->firstOrFail();
        $targetStatus = VendorVerificationStatus::from($validated['status']);

        VendorVerification::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'provider_name' => $validated['provider_name'] ?? 'manual_admin',
            'external_reference_id' => $validated['external_reference_id'] ?? null,
            'status' => $targetStatus->value,
            'rejection_reason_code' => $validated['rejection_reason_code'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'submitted_at' => CarbonImmutable::now(),
            'resolved_at' => CarbonImmutable::now(),
        ]);

        $vendor->verification_status = $targetStatus;
        if ($targetStatus === VendorVerificationStatus::Verified) {
            $vendor->verified_at = CarbonImmutable::now();
        } elseif ($targetStatus === VendorVerificationStatus::Rejected) {
            $vendor->verification_rejected_at = CarbonImmutable::now();
        }
        $vendor->save();

        return response()->json([
            'message' => 'Vendor verification recorded.',
            'data' => $vendor,
        ]);
    }

    public function payoutsIndex(): JsonResponse
    {
        $payouts = PayoutRequest::with(['vendor', 'allocations'])->latest('id')->paginate(20);

        return response()->json([
            'data' => $payouts,
        ]);
    }

    public function approvePayout(int $id, Request $request, PayoutServiceInterface $service): JsonResponse
    {
        $user = $request->user();
        $userId = $user !== null ? (int) $user->id : 1;
        $payout = $service->approvePayout($id, (int) $userId);

        return response()->json([
            'message' => 'Payout approved.',
            'data' => $payout,
        ]);
    }

    public function finalizePayout(Request $request, int $id, PayoutServiceInterface $service): JsonResponse
    {
        $validated = $request->validate([
            'settlement_reference' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $settlementReference = (string) $validated['settlement_reference'];
        $metadata = (array) ($validated['metadata'] ?? []);

        $payout = $service->finalizePayout($id, $settlementReference, $metadata);

        return response()->json([
            'message' => 'Payout finalized and settled.',
            'data' => $payout,
        ]);
    }
}
