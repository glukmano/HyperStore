<?php

declare(strict_types=1);

namespace Tests\Feature\DigitalDelivery;

use App\Models\User;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\CustomerProfile;
use Modules\Customers\Services\CustomerProfileService;
use Modules\DigitalDelivery\Enums\EntitlementType;
use Modules\DigitalDelivery\Exceptions\DownloadLimitExceededException;
use Modules\DigitalDelivery\Exceptions\EntitlementNotGrantedException;
use Modules\DigitalDelivery\Models\CustomerEntitlement;
use Modules\DigitalDelivery\Models\DigitalAsset;
use Modules\DigitalDelivery\Services\DigitalAssetDeliveryService;
use Modules\Order\Models\OrderItem;
use Modules\Payment\Events\PaymentCaptured;
use Tests\Feature\Ledger\LedgerTestCaseTrait;
use Tests\TestCase;

/**
 * Owner Delta §13/D.36/D.37: entitlements are granted only once payment
 * genuinely succeeds. Digital-download consumption is reserved atomically
 * under a row lock BEFORE any byte streams — the sequence is
 * "lock -> check -> reserve -> commit", never "check -> stream -> increment
 * later."
 */
class DigitalEntitlementAndDeliveryTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
        $this->provisionSystemAccounts();
    }

    private function makeDigitalProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'DIGI-'.uniqid(),
            'name' => 'Digital Good',
            'slug' => 'digi-'.uniqid(),
            'product_type' => 'digital',
            'status' => 'active',
        ]);
    }

    private function makeDigitalOrderItem(int $orderId, int $productId): OrderItem
    {
        return OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_type_snapshot' => 'digital',
            'sku_snapshot' => 'DIGI-1',
            'name_snapshot' => 'Digital Good',
            'quantity' => '1',
            'unit_price_minor' => 2000,
            'subtotal_minor' => 2000,
            'total_minor' => 2000,
        ]);
    }

    public function test_entitlement_is_granted_when_payment_captures(): void
    {
        $order = $this->createOrder(2000, 'EUR');
        $order->update(['user_id' => $this->user->id]);
        $this->makeDigitalOrderItem($order->id, $this->makeDigitalProduct()->id);

        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 2000, 'EUR');

        event(new PaymentCaptured($payment, $tx));

        $profile = app(CustomerProfileService::class)->firstOrCreateFor($this->user);
        $entitlement = CustomerEntitlement::where('customer_profile_id', $profile->id)
            ->where('entitlement_type', EntitlementType::DigitalDownload->value)
            ->first();

        $this->assertNotNull($entitlement);
        $this->assertTrue($entitlement->isAccessible());
    }

    public function test_entitlement_grant_is_idempotent_for_a_duplicate_payment_captured_event(): void
    {
        $order = $this->createOrder(2000, 'EUR');
        $order->update(['user_id' => $this->user->id]);
        $this->makeDigitalOrderItem($order->id, $this->makeDigitalProduct()->id);

        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 2000, 'EUR');

        event(new PaymentCaptured($payment, $tx));
        event(new PaymentCaptured($payment, $tx));

        $profile = app(CustomerProfileService::class)->firstOrCreateFor($this->user);
        $this->assertSame(1, CustomerEntitlement::where('customer_profile_id', $profile->id)->count());
    }

    public function test_download_reservation_increments_used_count_before_any_streaming_and_rejects_when_exhausted(): void
    {
        $user2 = User::factory()->create();
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user2->id]);

        $entitlement = CustomerEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => $profile->id,
            'entitlement_type' => EntitlementType::DigitalDownload,
            'source_type' => 'order_item',
            'source_uuid' => 'oi-1',
            'granted_at' => now(),
            'max_uses' => 2,
            'used_count' => 0,
        ]);

        $service = app(DigitalAssetDeliveryService::class);

        $service->reserveDownload($this->tenant->id, (int) $entitlement->id, 'iphash1', 'agent1');
        $entitlement->refresh();
        $this->assertSame(1, $entitlement->used_count);

        $service->reserveDownload($this->tenant->id, (int) $entitlement->id, 'iphash1', 'agent1');
        $entitlement->refresh();
        $this->assertSame(2, $entitlement->used_count);

        $this->expectException(DownloadLimitExceededException::class);
        $service->reserveDownload($this->tenant->id, (int) $entitlement->id, 'iphash1', 'agent1');
    }

    public function test_a_revoked_entitlement_rejects_download(): void
    {
        $user2 = User::factory()->create();
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user2->id]);

        $entitlement = CustomerEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => $profile->id,
            'entitlement_type' => EntitlementType::DigitalDownload,
            'source_type' => 'order_item',
            'source_uuid' => 'oi-2',
            'granted_at' => now(),
            'revoked_at' => now(),
            'used_count' => 0,
        ]);

        $this->expectException(EntitlementNotGrantedException::class);
        app(DigitalAssetDeliveryService::class)->reserveDownload($this->tenant->id, (int) $entitlement->id, 'iphash2', 'agent2');
    }

    public function test_an_expired_entitlement_rejects_download(): void
    {
        $user2 = User::factory()->create();
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user2->id]);

        $entitlement = CustomerEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => $profile->id,
            'entitlement_type' => EntitlementType::DigitalDownload,
            'source_type' => 'order_item',
            'source_uuid' => 'oi-3',
            'granted_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
            'used_count' => 0,
        ]);

        $this->expectException(EntitlementNotGrantedException::class);
        app(DigitalAssetDeliveryService::class)->reserveDownload($this->tenant->id, (int) $entitlement->id, 'iphash3', 'agent3');
    }

    public function test_pinned_digital_asset_version_is_snapshotted_onto_the_order_item_at_grant_time(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'DIGI-PIN',
            'name' => 'Pinned Digital',
            'slug' => 'digi-pin',
            'product_type' => 'digital',
            'status' => 'active',
        ]);

        DigitalAsset::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'disk' => 'local',
            'path' => 'assets/v1.zip',
            'version' => 1,
            'max_downloads' => 3,
        ]);

        $order = $this->createOrder(2000, 'EUR');
        $order->update(['user_id' => $this->user->id]);
        $item = $this->makeDigitalOrderItem($order->id, $product->id);

        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 2000, 'EUR');
        event(new PaymentCaptured($payment, $tx));

        $item->refresh();
        $this->assertNotNull($item->entitlement_terms_snapshot);
        $this->assertSame(3, $item->entitlement_terms_snapshot['max_downloads']);

        // A later, higher-version asset must NOT retroactively change what
        // this already-granted entitlement is pinned to.
        DigitalAsset::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'disk' => 'local',
            'path' => 'assets/v2.zip',
            'version' => 2,
            'max_downloads' => 999,
        ]);

        $item->refresh();
        $this->assertSame(3, $item->entitlement_terms_snapshot['max_downloads']);
    }
}
