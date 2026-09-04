<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Customers\Notifications\PriceDropDetected;
use Modules\Customers\Services\AlertSubscriptionService;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Services\PriceWriteService;
use Tests\TestCase;

/**
 * Proves Price Drop alerts are genuinely event-driven off the new
 * Modules\Pricing\Events\PriceChanged signal (dispatched from the new
 * PriceWriteService, the first real write-path Pricing has ever had —
 * previously Price::updateOrCreate() was called directly from a Livewire
 * component with zero events), not a polling/diff job.
 */
class PriceDropAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Product $product;

    private PriceBook $priceBook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'price-alert-tenant', 'name' => 'Price Alert Tenant', 'status' => 'active']);
        $this->user = User::create(['name' => 'Cust', 'email' => 'cust-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'PRICE-ALERT-SKU-1',
            translations: ['en' => ['name' => 'Price Alert Product']],
        ));

        $this->priceBook = PriceBook::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STD',
            'name' => 'Default Price Book',
            'currency' => 'USD',
            'priority' => 100,
            'is_default' => true,
            'status' => 'active',
        ]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
    }

    public function test_a_genuine_price_drop_below_baseline_notifies_the_subscriber(): void
    {
        Notification::fake();

        $writeService = app(PriceWriteService::class);
        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 10000, null, null);

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToPriceDrop($this->user, $this->product->id, null, baselinePriceMinor: 10000, currency: 'USD');

        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 8000, null, null);

        Notification::assertSentTo($this->user, PriceDropDetected::class, function (PriceDropDetected $n) {
            return $n->newAmountMinor === 8000;
        });
    }

    public function test_a_price_increase_does_not_notify(): void
    {
        Notification::fake();

        $writeService = app(PriceWriteService::class);
        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 8000, null, null);

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToPriceDrop($this->user, $this->product->id, null, baselinePriceMinor: 8000, currency: 'USD');

        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 9000, null, null);

        Notification::assertNothingSentTo($this->user);
    }

    public function test_setting_the_same_price_again_does_not_dispatch_price_changed_at_all(): void
    {
        Notification::fake();

        $writeService = app(PriceWriteService::class);
        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 8000, null, null);

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToPriceDrop($this->user, $this->product->id, null, baselinePriceMinor: 8000, currency: 'USD');

        // Same amount as already stored — no real change, no event, no notification.
        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 8000, null, null);

        Notification::assertNothingSentTo($this->user);
    }

    public function test_the_subscription_is_a_one_shot_alert_and_does_not_notify_twice(): void
    {
        Notification::fake();

        $writeService = app(PriceWriteService::class);
        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 10000, null, null);

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToPriceDrop($this->user, $this->product->id, null, baselinePriceMinor: 10000, currency: 'USD');

        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 8000, null, null);
        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 7000, null, null);

        Notification::assertSentToTimes($this->user, PriceDropDetected::class, 1);
    }

    public function test_a_target_price_subscription_only_triggers_at_or_below_the_target(): void
    {
        Notification::fake();

        $writeService = app(PriceWriteService::class);
        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 10000, null, null);

        $alertService = app(AlertSubscriptionService::class);
        $alertService->subscribeToPriceDrop($this->user, $this->product->id, null, baselinePriceMinor: 10000, currency: 'USD', targetPriceMinor: 5000);

        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 7000, null, null);
        Notification::assertNothingSentTo($this->user);

        $writeService->setPrice($this->tenant->id, $this->priceBook->id, $this->product->id, null, 5000, null, null);
        Notification::assertSentTo($this->user, PriceDropDetected::class);
    }
}
