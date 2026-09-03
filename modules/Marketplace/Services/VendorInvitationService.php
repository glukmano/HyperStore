<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Enums\VendorInvitationStatus;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Exceptions\VendorInvitationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorInvitation;
use Modules\Marketplace\Models\VendorUser;

final class VendorInvitationService
{
    public function __construct(
        private readonly MarketplaceConcurrencyBarrierInterface $barrier,
    ) {}

    /**
     * @return array{invitation: VendorInvitation, plaintext_token: string}
     */
    public function inviteStaff(int $tenantId, int $vendorId, string $email, VendorRole $role): array
    {
        if ($role === VendorRole::Owner) {
            throw VendorInvitationException::ownerRoleForbidden();
        }

        return DB::transaction(function () use ($tenantId, $vendorId, $email, $role): array {
            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendorId);
            $plan = $vendor->plan;

            // Check staff quota under vendor lock
            $activeStaffCount = VendorUser::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('is_active', true)
                ->count();

            $pendingInvitesCount = VendorInvitation::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('status', VendorInvitationStatus::Pending->value)
                ->where('expires_at', '>', CarbonImmutable::now())
                ->count();

            if (($activeStaffCount + $pendingInvitesCount) >= $plan->staff_limit) {
                throw VendorInvitationException::quotaExceeded($plan->staff_limit);
            }

            $this->barrier->wait('staff_invite_quota_checked');

            $plaintextToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $plaintextToken);

            /** @var VendorInvitation $invitation */
            $invitation = VendorInvitation::create([
                'tenant_id' => $tenantId,
                'vendor_id' => $vendorId,
                'email' => strtolower(trim($email)),
                'role' => $role,
                'token_hash' => $tokenHash,
                'expires_at' => CarbonImmutable::now()->addDays(7),
                'status' => VendorInvitationStatus::Pending,
            ]);

            return [
                'invitation' => $invitation,
                'plaintext_token' => $plaintextToken,
            ];
        });
    }

    public function acceptInvitation(string $plaintextToken, User $user): VendorUser
    {
        $tokenHash = hash('sha256', $plaintextToken);

        return DB::transaction(function () use ($tokenHash, $user): VendorUser {
            /** @var VendorInvitation|null $invitation */
            $invitation = VendorInvitation::where('token_hash', $tokenHash)->lockForUpdate()->first();

            if ($invitation === null || $invitation->expires_at->isPast()) {
                throw VendorInvitationException::invalidToken();
            }

            if ($invitation->status === VendorInvitationStatus::Accepted) {
                // Idempotent return if already accepted by the same user
                if ($invitation->accepted_by_user_id === $user->id) {
                    return VendorUser::where('tenant_id', $invitation->tenant_id)
                        ->where('vendor_id', $invitation->vendor_id)
                        ->where('user_id', $user->id)
                        ->firstOrFail();
                }
                throw VendorInvitationException::alreadyAccepted();
            }

            if ($invitation->status !== VendorInvitationStatus::Pending) {
                throw VendorInvitationException::revoked();
            }

            $this->barrier->wait('invitation_accept_token_locked');

            // Find or create VendorUser
            /** @var VendorUser|null $existingMember */
            $existingMember = VendorUser::where('tenant_id', $invitation->tenant_id)
                ->where('vendor_id', $invitation->vendor_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingMember !== null) {
                $existingMember->role = $invitation->role;
                $existingMember->is_active = true;
                $existingMember->save();
                $member = $existingMember;
            } else {
                $member = VendorUser::create([
                    'tenant_id' => $invitation->tenant_id,
                    'vendor_id' => $invitation->vendor_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                    'is_active' => true,
                ]);
            }

            $invitation->status = VendorInvitationStatus::Accepted;
            $invitation->accepted_by_user_id = $user->id;
            $invitation->save();

            return $member;
        });
    }
}
