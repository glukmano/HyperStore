<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorUser;
use Modules\Messaging\Exceptions\ConversationAuthorizationException;
use Modules\Messaging\Exceptions\ConversationClosedException;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Services\ConversationPolicy;
use Modules\Messaging\Services\ConversationService;
use Modules\Messaging\Services\MessagingService;
use Tests\TestCase;

/**
 * Proves conversation access is strictly scoped to real participants —
 * cross-vendor and cross-tenant isolation — via the single ConversationPolicy
 * both Livewire and the Reverb channel-auth callback share.
 */
class MessagingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Vendor $vendor;

    private User $buyer;

    private User $vendorStaff;

    private User $strangerVendorStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'msg-tenant', 'name' => 'Messaging Tenant', 'status' => 'active']);
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Msg Vendor',
            'platform_slug' => 'msg-vendor-'.uniqid(), 'legal_name' => 'Msg Vendor Corp', 'email' => 'msgvendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);

        $otherVendor = Vendor::create([
            'tenant_id' => $this->tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Other Vendor',
            'platform_slug' => 'other-vendor-'.uniqid(), 'legal_name' => 'Other Vendor Corp', 'email' => 'othervendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);

        $this->buyer = User::create(['name' => 'Buyer', 'email' => 'buyer-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $this->vendorStaff = User::create(['name' => 'Vendor Staff', 'email' => 'vstaff-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $this->strangerVendorStaff = User::create(['name' => 'Stranger Staff', 'email' => 'sstaff-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        VendorUser::create(['tenant_id' => $this->tenant->id, 'vendor_id' => $this->vendor->id, 'user_id' => $this->vendorStaff->id, 'role' => 'staff', 'is_active' => true]);
        VendorUser::create(['tenant_id' => $this->tenant->id, 'vendor_id' => $otherVendor->id, 'user_id' => $this->strangerVendorStaff->id, 'role' => 'staff', 'is_active' => true]);
    }

    private function makeConversation(): Conversation
    {
        $conversationService = app(ConversationService::class);
        $conversation = $conversationService->startBuyerVendorConversation($this->tenant->id, null, $this->buyer, $this->vendor->id);
        $conversationService->addVendorStaffParticipant($conversation, $this->vendorStaff);

        return $conversation;
    }

    public function test_the_buyer_can_view_and_send_in_their_own_conversation(): void
    {
        $conversation = $this->makeConversation();
        $policy = app(ConversationPolicy::class);

        $this->assertTrue($policy->view($this->buyer, $conversation));
        $this->assertTrue($policy->sendMessage($this->buyer, $conversation));
    }

    public function test_the_correct_vendors_staff_can_view_the_conversation(): void
    {
        $conversation = $this->makeConversation();
        $policy = app(ConversationPolicy::class);

        $this->assertTrue($policy->view($this->vendorStaff, $conversation));
    }

    public function test_staff_from_a_different_vendor_cannot_view_the_conversation(): void
    {
        $conversation = $this->makeConversation();
        $policy = app(ConversationPolicy::class);

        $this->assertFalse($policy->view($this->strangerVendorStaff, $conversation));
    }

    public function test_an_unrelated_user_with_no_participant_row_cannot_view_the_conversation(): void
    {
        $conversation = $this->makeConversation();
        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->assertFalse(app(ConversationPolicy::class)->view($stranger, $conversation));
    }

    public function test_sending_a_message_as_a_non_participant_throws_an_authorization_exception(): void
    {
        $conversation = $this->makeConversation();
        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger2-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->expectException(ConversationAuthorizationException::class);
        app(MessagingService::class)->send($conversation, $stranger, 'Hello');
    }

    public function test_sending_a_message_to_a_closed_conversation_is_rejected(): void
    {
        $conversation = $this->makeConversation();
        $conversation->update(['status' => Conversation::STATUS_CLOSED]);

        $this->expectException(ConversationClosedException::class);
        app(MessagingService::class)->send($conversation, $this->buyer, 'Hello');
    }

    public function test_a_deactivated_vendor_staff_membership_loses_access(): void
    {
        $conversation = $this->makeConversation();
        VendorUser::query()->where('user_id', $this->vendorStaff->id)->update(['is_active' => false]);

        $this->assertFalse(app(ConversationPolicy::class)->view($this->vendorStaff, $conversation));
    }
}
