<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Modules\Customers\Models\CustomerProfile;
use Tests\TestCase;

/**
 * Phase-18 Final Completion Delta §6(D): a queued Notification must
 * render using the RECIPIENT's resolved Locale, never the queue worker
 * process's own ambient app()->getLocale(). Proven end-to-end: the
 * "worker" locale is deliberately left set to English while the
 * recipient's preference is Arabic, and the rendered notification
 * content is asserted to be Arabic anyway.
 */
class QueuedNotificationLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_preferred_locale_prioritizes_customer_profile_over_the_default_locale_column(): void
    {
        $tenant = Tenant::create(['slug' => 'qnl-tenant-'.uniqid(), 'name' => 'QNL Tenant', 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($tenant->id, $tenant->name));

        $user = User::create(['name' => 'Shopper', 'email' => 'qnl-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false, 'default_locale' => 'en']);
        CustomerProfile::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'preferred_locale' => 'ar']);

        $this->assertSame('ar', $user->preferredLocale());
    }

    public function test_user_preferred_locale_falls_back_to_default_locale_column_when_no_customer_profile(): void
    {
        $user = User::create(['name' => 'Staff', 'email' => 'qnl-staff-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false, 'default_locale' => 'de']);

        $this->assertSame('de', $user->preferredLocale());
    }

    public function test_user_preferred_locale_is_null_when_nothing_is_set(): void
    {
        $user = User::create(['name' => 'Nobody', 'email' => 'qnl-nobody-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false, 'default_locale' => '']);

        $this->assertNull($user->preferredLocale());
    }

    public function test_a_queued_notification_renders_in_the_recipients_locale_not_the_worker_ambient_locale(): void
    {
        $tenant = Tenant::create(['slug' => 'qnl-tenant2-'.uniqid(), 'name' => 'QNL Tenant 2', 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($tenant->id, $tenant->name));

        $user = User::create(['name' => 'Recipient', 'email' => 'qnl-recipient-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        CustomerProfile::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'preferred_locale' => 'ar']);

        // The "queue worker" ambient locale — deliberately the OPPOSITE
        // of the recipient's own preference.
        app()->setLocale('en');

        $user->notify(new LocaleProbeTestNotification);

        $stored = $user->notifications()->latest()->first();
        $this->assertNotNull($stored);
        $this->assertSame('أضف إلى السلة', $stored->data['rendered']);

        // The worker's own ambient locale must be restored/unaffected
        // for any code running after the notification is sent.
        $this->assertSame('en', app()->getLocale());
    }
}

class LocaleProbeTestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        // A real first-party catalog string (lang/ar.json) — proves the
        // ACTUAL translator resolves to the recipient's locale during
        // rendering, not a synthetic stand-in.
        return ['rendered' => __('Add to Cart')];
    }
}
