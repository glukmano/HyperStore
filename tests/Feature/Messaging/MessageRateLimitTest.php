<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Messaging\Exceptions\MessageRateLimitExceededException;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Services\ConversationService;
use Modules\Messaging\Services\MessagingService;
use Tests\TestCase;

class MessageRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_beyond_the_per_minute_limit_is_rejected(): void
    {
        $tenant = Tenant::create(['slug' => 'rl-tenant', 'name' => 'Rate Limit Tenant', 'status' => 'active']);
        $plan = VendorPlan::create(['tenant_id' => $tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $vendor = Vendor::create([
            'tenant_id' => $tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'RL Vendor',
            'platform_slug' => 'rl-vendor-'.uniqid(), 'legal_name' => 'RL Vendor Corp', 'email' => 'rlvendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);
        $buyer = User::create(['name' => 'Buyer', 'email' => 'rlbuyer-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($tenant->id, null, $buyer, $vendor->id);
        $messagingService = app(MessagingService::class);

        for ($i = 0; $i < 20; $i++) {
            $messagingService->send($conversation, $buyer, "Message {$i}");
        }

        $this->assertSame(20, Message::query()->count());

        $this->expectException(MessageRateLimitExceededException::class);
        $messagingService->send($conversation, $buyer, 'One too many');
    }
}
