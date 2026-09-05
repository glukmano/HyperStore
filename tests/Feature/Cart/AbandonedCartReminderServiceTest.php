<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Cart\Models\AbandonedCartReminderLog;
use Modules\Cart\Models\Cart;
use Modules\Cart\Notifications\AbandonedCartReminder;
use Modules\Cart\Services\AbandonedCartReminderService;
use Modules\Customers\Models\CustomerProfile;
use Tests\TestCase;

class AbandonedCartReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private AbandonedCartReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Cart Tenant', 'slug' => 'cart-tenant']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'slug' => 'store-'.Str::random(6), 'status' => 'active', 'url' => 'https://s.example.com']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'M'.Str::random(4), 'name' => 'M', 'default_currency_code' => 'EUR', 'default_locale_code' => 'en', 'timezone' => 'UTC', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'web-'.Str::random(6), 'is_active' => true]);

        $this->service = app(AbandonedCartReminderService::class);
    }

    private function makeCart(?int $userId, string $updatedAt): Cart
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $userId,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => 'active',
        ]);
        $cart->timestamps = false;
        $cart->updated_at = $updatedAt;
        $cart->save();

        return $cart;
    }

    public function test_guest_carts_are_never_targeted_no_consent_signal_exists(): void
    {
        Notification::fake();

        $this->makeCart(userId: null, updatedAt: now()->subHours(2)->toDateTimeString());

        $sent = $this->service->sendDueReminders();
        $this->assertSame(0, $sent);
    }

    public function test_authenticated_customer_without_marketing_opt_in_is_not_sent_a_reminder(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'marketing_opt_in' => false]);
        $this->makeCart(userId: $user->id, updatedAt: now()->subHours(2)->toDateTimeString());

        $sent = $this->service->sendDueReminders();
        $this->assertSame(0, $sent);
    }

    public function test_opted_in_customer_receives_the_due_reminder_tier(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'marketing_opt_in' => true]);
        $cart = $this->makeCart(userId: $user->id, updatedAt: now()->subHours(2)->toDateTimeString());

        $sent = $this->service->sendDueReminders();
        $this->assertSame(1, $sent);

        Notification::assertSentTo($user, AbandonedCartReminder::class);
        $this->assertDatabaseHas('abandoned_cart_reminders', [
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'reminder_sequence' => 1,
        ]);
    }

    public function test_a_reminder_is_never_sent_twice_for_the_same_tier(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'marketing_opt_in' => true]);
        $this->makeCart(userId: $user->id, updatedAt: now()->subHours(2)->toDateTimeString());

        $this->service->sendDueReminders();
        $sentSecondRun = $this->service->sendDueReminders();

        $this->assertSame(0, $sentSecondRun);
        $this->assertSame(1, AbandonedCartReminderLog::count());
    }

    public function test_a_converted_cart_is_never_sent_a_reminder_even_if_past_threshold(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'marketing_opt_in' => true]);
        $cart = $this->makeCart(userId: $user->id, updatedAt: now()->subHours(2)->toDateTimeString());
        $cart->status = 'converted';
        $cart->save();

        $sent = $this->service->sendDueReminders();
        $this->assertSame(0, $sent);
    }
}
