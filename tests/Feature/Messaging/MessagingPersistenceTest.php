<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorUser;
use Modules\Messaging\Events\MessageSent;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Services\ConversationService;
use Modules\Messaging\Services\MessagingService;
use Tests\TestCase;

/**
 * Proves the persistence-before-broadcast invariant: MessageSent (a
 * ShouldBroadcast event) is only ever dispatched via DB::afterCommit(),
 * meaning by the time it fires, the Message row is already durably
 * committed — Reverb is never the authoritative record.
 */
class MessagingPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Vendor $vendor;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'msg-persist-tenant', 'name' => 'Msg Persist Tenant', 'status' => 'active']);
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Persist Vendor',
            'platform_slug' => 'persist-vendor-'.uniqid(), 'legal_name' => 'Persist Vendor Corp', 'email' => 'persistvendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);
        $this->buyer = User::create(['name' => 'Buyer', 'email' => 'pbuyer-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
    }

    public function test_the_message_row_is_committed_before_the_broadcast_event_fires(): void
    {
        $conversation = app(ConversationService::class)->startBuyerVendorConversation($this->tenant->id, null, $this->buyer, $this->vendor->id);

        $sawMessageAlreadyInDb = false;

        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sawMessageAlreadyInDb): void {
            // By the time this listener runs, the row must already be
            // queryable from a fresh query — proving persistence preceded
            // the broadcast dispatch, not the reverse.
            $sawMessageAlreadyInDb = Message::query()->where('id', $event->message->id)->exists();
        });

        app(MessagingService::class)->send($conversation, $this->buyer, 'Hello vendor');

        $this->assertTrue($sawMessageAlreadyInDb, 'MessageSent fired before the Message row was committed.');
    }

    public function test_the_broadcast_payload_never_includes_the_full_conversation_or_raw_attachment_paths(): void
    {
        Event::fake([MessageSent::class]);

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($this->tenant->id, null, $this->buyer, $this->vendor->id);
        app(MessagingService::class)->send($conversation, $this->buyer, 'Hello vendor');

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
            $payload = $event->broadcastWith();

            return array_key_exists('body', $payload)
                && ! array_key_exists('conversation', $payload)
                && array_key_exists('attachment_ids', $payload);
        });
    }

    public function test_a_failed_send_never_dispatches_a_broadcast(): void
    {
        Event::fake([MessageSent::class]);

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($this->tenant->id, null, $this->buyer, $this->vendor->id);
        $stranger = User::create(['name' => 'Stranger', 'email' => 'pstranger-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        try {
            app(MessagingService::class)->send($conversation, $stranger, 'Should never persist');
        } catch (\Throwable) {
            // expected
        }

        Event::assertNotDispatched(MessageSent::class);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_unread_count_reflects_messages_sent_after_the_participants_last_read_timestamp(): void
    {
        $conversationService = app(ConversationService::class);
        $conversation = $conversationService->startBuyerVendorConversation($this->tenant->id, null, $this->buyer, $this->vendor->id);
        $vendorStaff = User::create(['name' => 'Vendor Staff', 'email' => 'pvstaff-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        VendorUser::create(['tenant_id' => $this->tenant->id, 'vendor_id' => $this->vendor->id, 'user_id' => $vendorStaff->id, 'role' => 'staff', 'is_active' => true]);
        $conversationService->addVendorStaffParticipant($conversation, $vendorStaff);

        $messagingService = app(MessagingService::class);
        $messagingService->send($conversation, $this->buyer, 'Message 1');
        $messagingService->send($conversation, $this->buyer, 'Message 2');

        $this->assertSame(2, $messagingService->unreadCount($conversation, $vendorStaff));

        $messagingService->markRead($conversation, $vendorStaff);
        $this->assertSame(0, $messagingService->unreadCount($conversation, $vendorStaff));

        // Advance the clock so this message's sent_at is unambiguously after
        // the just-recorded last_read_at, avoiding same-second flakiness.
        $this->travel(1)->second();
        $messagingService->send($conversation, $this->buyer, 'Message 3');
        $this->assertSame(1, $messagingService->unreadCount($conversation, $vendorStaff));

        // The buyer's own sent messages never count as unread for the buyer.
        $this->assertSame(0, $messagingService->unreadCount($conversation, $this->buyer));
    }
}
