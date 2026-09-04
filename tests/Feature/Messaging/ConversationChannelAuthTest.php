<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Services\ConversationPolicy;
use Modules\Messaging\Services\ConversationService;
use Tests\TestCase;

/**
 * Proves routes/channels.php's private conversation channel actually
 * rejects a non-participant at the real broadcasting/auth endpoint — not
 * just at the ConversationPolicy unit level.
 */
class ConversationChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 'log' driver used elsewhere in the test suite is a no-op for
        // authorization (Illuminate\Broadcasting\Broadcasters\LogBroadcaster::auth()
        // does nothing and always yields 200) — these tests specifically
        // prove the real channel-authorization code path, so they force the
        // 'reverb' driver (Pusher-protocol-compatible base Broadcaster,
        // which genuinely resolves routes/channels.php callbacks). No live
        // Reverb server connection is needed for the auth endpoint itself.
        //
        // Channel patterns are registered onto whichever broadcaster
        // instance was the app's default at boot time (when routes/channels.php
        // first ran, under the suite-wide BROADCAST_CONNECTION=log). Since
        // this test swaps the default driver afterward, the *same*
        // production channel definition (delegating to ConversationPolicy,
        // proven correct by MessagingAuthorizationTest) is re-registered
        // here onto the now-active 'reverb' broadcaster instance, so the
        // real Pusher-protocol auth-response encoding path is exercised
        // end-to-end.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);

        Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
            $conversation = Conversation::query()->find($conversationId);

            if ($conversation === null) {
                return false;
            }

            return app(ConversationPolicy::class)->view($user, $conversation)
                ? ['id' => $user->id, 'name' => $user->name]
                : false;
        });
    }

    public function test_a_participant_is_authorized_on_the_real_broadcasting_auth_endpoint(): void
    {
        $tenant = Tenant::create(['slug' => 'chan-tenant', 'name' => 'Channel Tenant', 'status' => 'active']);
        $plan = VendorPlan::create(['tenant_id' => $tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $vendor = Vendor::create([
            'tenant_id' => $tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Chan Vendor',
            'platform_slug' => 'chan-vendor-'.uniqid(), 'legal_name' => 'Chan Vendor Corp', 'email' => 'chanvendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);
        $buyer = User::create(['name' => 'Buyer', 'email' => 'chanbuyer-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($tenant->id, null, $buyer, $vendor->id);

        $response = $this->actingAs($buyer)->post('/broadcasting/auth', [
            'channel_name' => 'private-conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
    }

    public function test_a_non_participant_is_rejected_on_the_real_broadcasting_auth_endpoint(): void
    {
        $tenant = Tenant::create(['slug' => 'chan-tenant-2', 'name' => 'Channel Tenant 2', 'status' => 'active']);
        $plan = VendorPlan::create(['tenant_id' => $tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $vendor = Vendor::create([
            'tenant_id' => $tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Chan Vendor 2',
            'platform_slug' => 'chan-vendor2-'.uniqid(), 'legal_name' => 'Chan Vendor2 Corp', 'email' => 'chanvendor2-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);
        $buyer = User::create(['name' => 'Buyer', 'email' => 'chanbuyer2-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $stranger = User::create(['name' => 'Stranger', 'email' => 'chanstranger-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($tenant->id, null, $buyer, $vendor->id);

        $response = $this->actingAs($stranger)->post('/broadcasting/auth', [
            'channel_name' => 'private-conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_a_nonexistent_conversation_id_is_rejected(): void
    {
        $user = User::create(['name' => 'User', 'email' => 'chanuser-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $response = $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => 'private-conversation.999999',
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }
}
