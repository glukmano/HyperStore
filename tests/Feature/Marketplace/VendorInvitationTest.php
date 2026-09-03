<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Exceptions\VendorInvitationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorInvitation;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorUser;
use Modules\Marketplace\Services\VendorInvitationService;
use Tests\TestCase;

class VendorInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Vendor $vendor;

    private VendorPlan $plan;

    private VendorInvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Invite Tenant', 'slug' => 'invite-tenant']);
        $this->plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Invite Plan',
            'code' => 'invite-plan',
            'staff_limit' => 2, // Quota = 2
        ]);
        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $this->plan->id,
            'name' => 'Invite Vendor',
            'platform_slug' => 'invite-vendor',
            'legal_name' => 'Invite Vendor Corp',
            'email' => 'invite@vendor.com',
            'payout_currency' => 'EUR',
        ]);

        // 1 active owner takes 1 slot
        VendorUser::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'user_id' => User::factory()->create()->id,
            'role' => VendorRole::Owner,
            'is_active' => true,
        ]);

        $this->service = app(VendorInvitationService::class);
    }

    public function test_invitation_cannot_target_owner_role(): void
    {
        $this->expectException(VendorInvitationException::class);
        $this->service->inviteStaff($this->tenant->id, $this->vendor->id, 'bad@test.com', VendorRole::Owner);
    }

    public function test_staff_quota_enforced_strictly(): void
    {
        // 1 owner already exists. Staff limit is 2.
        // First invite succeeds (active=1, pending=1 -> total=2)
        $res1 = $this->service->inviteStaff($this->tenant->id, $this->vendor->id, 'staff1@test.com', VendorRole::Staff);
        $this->assertNotEmpty($res1['plaintext_token']);

        // Second invite exceeds quota (active=1, pending=1 -> total 2 >= 2)
        $this->expectException(VendorInvitationException::class);
        $this->service->inviteStaff($this->tenant->id, $this->vendor->id, 'staff2@test.com', VendorRole::Staff);
    }

    public function test_invitation_accepted_cleanly_and_is_single_use(): void
    {
        $res = $this->service->inviteStaff($this->tenant->id, $this->vendor->id, 'manager@test.com', VendorRole::Manager);
        $token = $res['plaintext_token'];

        $targetUser = User::factory()->create(['email' => 'manager@test.com']);
        $member = $this->service->acceptInvitation($token, $targetUser);

        $this->assertSame(VendorRole::Manager, $member->role);
        $this->assertTrue($member->is_active);

        // Replay with different user fails
        $otherUser = User::factory()->create();
        $this->expectException(VendorInvitationException::class);
        $this->service->acceptInvitation($token, $otherUser);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $res = $this->service->inviteStaff($this->tenant->id, $this->vendor->id, 'expired@test.com', VendorRole::Staff);
        $token = $res['plaintext_token'];

        /** @var VendorInvitation $invitation */
        $invitation = VendorInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        $invitation->expires_at = CarbonImmutable::now()->subDay();
        $invitation->save();

        $user = User::factory()->create();
        $this->expectException(VendorInvitationException::class);
        $this->service->acceptInvitation($token, $user);
    }
}
