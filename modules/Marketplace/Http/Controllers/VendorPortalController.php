<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\DTOs\VendorRegistrationDTO;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Exceptions\VendorNotFoundException;
use Modules\Marketplace\Models\VendorUser;
use Modules\Marketplace\Services\VendorInvitationService;
use Modules\Marketplace\Services\VendorOwnershipService;
use Modules\Marketplace\Services\VendorRegistrationService;

final class VendorPortalController extends Controller
{
    public function register(Request $request, VendorRegistrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'platform_slug' => 'required|string|min:3|max:64',
            'legal_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'vendor_plan_id' => 'required|integer',
            'tax_id' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:64',
            'default_store_id' => 'nullable|integer',
            'payout_currency' => 'nullable|string|size:3',
        ]);

        $userId = (int) $request->user()?->id;

        $dto = new VendorRegistrationDTO(
            tenantId: (int) $validated['tenant_id'],
            name: (string) $validated['name'],
            platformSlug: (string) $validated['platform_slug'],
            legalName: (string) $validated['legal_name'],
            email: (string) $validated['email'],
            vendorPlanId: (int) $validated['vendor_plan_id'],
            ownerUserId: $userId > 0 ? $userId : 1,
            taxId: $validated['tax_id'] ?? null,
            phone: $validated['phone'] ?? null,
            defaultStoreId: isset($validated['default_store_id']) ? (int) $validated['default_store_id'] : null,
            payoutCurrency: $validated['payout_currency'] ?? 'EUR',
        );

        $vendor = $service->registerVendor($dto);

        return response()->json([
            'message' => 'Vendor registered successfully.',
            'data' => $vendor,
        ], 201);
    }

    public function myVendor(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        /** @var VendorUser|null $membership */
        $membership = VendorUser::where('user_id', $userId)->where('is_active', true)->first();

        if ($membership === null) {
            throw new VendorNotFoundException('User does not belong to any active vendor.');
        }

        return response()->json([
            'data' => $membership->vendor->load(['plan', 'storefrontProfile', 'domains']),
            'role' => $membership->role->value,
        ]);
    }

    public function balances(Request $request, VendorPayableSubledgerServiceInterface $subledger): JsonResponse
    {
        $vendorId = (int) $request->query('vendor_id');
        $tenantId = (int) $request->query('tenant_id', 1);
        $currency = (string) $request->query('currency', 'EUR');

        $balances = $subledger->getBalances($tenantId, $vendorId, $currency);

        return response()->json([
            'data' => $balances->toArray(),
        ]);
    }

    public function requestPayout(Request $request, PayoutServiceInterface $payoutService): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'vendor_id' => 'required|integer',
            'amount_minor' => 'required|integer|min:1',
            'currency' => 'required|string|size:3',
            'destination_details' => 'nullable|array',
        ]);

        $payout = $payoutService->requestPayout(
            tenantId: (int) $validated['tenant_id'],
            vendorId: (int) $validated['vendor_id'],
            amountMinor: (int) $validated['amount_minor'],
            currency: (string) $validated['currency'],
            destinationDetails: $validated['destination_details'] ?? []
        );

        return response()->json([
            'message' => 'Payout requested successfully.',
            'data' => $payout,
        ], 201);
    }

    public function inviteStaff(Request $request, VendorInvitationService $service): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'vendor_id' => 'required|integer',
            'email' => 'required|email|max:255',
            'role' => 'required|string|in:manager,staff',
        ]);

        $result = $service->inviteStaff(
            tenantId: (int) $validated['tenant_id'],
            vendorId: (int) $validated['vendor_id'],
            email: (string) $validated['email'],
            role: VendorRole::from($validated['role'])
        );

        return response()->json([
            'message' => 'Invitation sent.',
            'invitation_uuid' => $result['invitation']->uuid,
            'plaintext_token' => $result['plaintext_token'],
        ], 201);
    }

    public function acceptInvite(Request $request, VendorInvitationService $service): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated');
        }
        $member = $service->acceptInvitation((string) $validated['token'], $user);

        return response()->json([
            'message' => 'Invitation accepted.',
            'data' => $member,
        ]);
    }

    public function transferOwnership(Request $request, VendorOwnershipService $service): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'vendor_id' => 'required|integer',
            'new_owner_user_id' => 'required|integer',
        ]);

        $newOwner = $service->transferOwnership(
            tenantId: (int) $validated['tenant_id'],
            vendorId: (int) $validated['vendor_id'],
            newOwnerUserId: (int) $validated['new_owner_user_id']
        );

        return response()->json([
            'message' => 'Ownership transferred successfully.',
            'data' => $newOwner,
        ]);
    }
}
