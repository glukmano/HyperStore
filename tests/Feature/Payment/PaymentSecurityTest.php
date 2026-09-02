<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentInitiationService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PaymentSecurityTest extends TestCase
{
    use PaymentTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
    }

    public function test_customer_can_initiate_and_view_payment_for_own_order(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', userId: $this->user->id);

        Sanctum::actingAs($this->user);

        // Initiate via API
        $response = $this->postJson("/api/v1/orders/{$order->uuid}/payments", [
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'captured');

        // View via API
        $viewResponse = $this->getJson("/api/v1/orders/{$order->uuid}/payments");
        $viewResponse->assertStatus(200);
        $viewResponse->assertJsonPath('data.amount_minor', 5000);
    }

    public function test_customer_cannot_access_another_customers_order_payment(): void
    {
        /** @var User $otherUser */
        $otherUser = User::factory()->create();
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', userId: $otherUser->id);

        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/v1/orders/{$order->uuid}/payments", [
            'amount_minor' => 5000,
            'currency' => 'EUR',
        ]);

        $response->assertStatus(403);

        $viewResponse = $this->getJson("/api/v1/orders/{$order->uuid}/payments");
        $viewResponse->assertStatus(403);
    }

    public function test_guest_can_access_payment_with_valid_guest_token(): void
    {
        $rawToken = 'secret-guest-token-12345';
        $tokenHash = hash('sha256', $rawToken);

        $order = $this->createOrder(
            grandTotalMinor: 5000,
            currency: 'EUR',
            userId: null,
            guestTokenHash: $tokenHash
        );

        $response = $this->withHeader('X-Order-Token', $rawToken)
            ->postJson("/api/v1/orders/{$order->uuid}/payments", [
                'amount_minor' => 5000,
                'currency' => 'EUR',
            ]);

        $response->assertStatus(201);

        $viewResponse = $this->withHeader('X-Order-Token', $rawToken)
            ->getJson("/api/v1/orders/{$order->uuid}/payments");

        $viewResponse->assertStatus(200);
    }

    public function test_guest_with_invalid_token_is_rejected(): void
    {
        $order = $this->createOrder(
            grandTotalMinor: 5000,
            currency: 'EUR',
            userId: null,
            guestTokenHash: hash('sha256', 'real-token')
        );

        $response = $this->withHeader('X-Order-Token', 'wrong-token')
            ->postJson("/api/v1/orders/{$order->uuid}/payments", [
                'amount_minor' => 5000,
                'currency' => 'EUR',
            ]);

        $response->assertStatus(403);
    }

    public function test_cross_tenant_access_is_concealed(): void
    {
        /** @var Tenant $tenantB */
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', userId: $this->user->id);

        // Switch context to Tenant B
        $this->contextManager->setTenant(TenantContext::from($tenantB->id));

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/v1/orders/{$order->uuid}/payments");
        $response->assertStatus(404);

        // Switch back to this->tenant
        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));
    }

    public function test_staff_rbac_enforcement_on_admin_payment_endpoints(): void
    {
        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));

        /** @var User $staffUser */
        $staffUser = User::factory()->create();

        // Create an authorized payment
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');
        $initResult = app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        ));
        $paymentUuid = $initResult['payment_uuid'];

        Sanctum::actingAs($staffUser);

        // Without permissions: 403
        $this->getJson("/api/v1/admin/payments/{$paymentUuid}")->assertStatus(403);
        $this->postJson("/api/v1/admin/payments/{$paymentUuid}/capture", ['amount_minor' => 5000])->assertStatus(403);
        $this->postJson("/api/v1/admin/payments/{$paymentUuid}/refund", ['amount_minor' => 5000])->assertStatus(403);
        $this->postJson("/api/v1/admin/payments/{$paymentUuid}/void")->assertStatus(403);

        // Grant permissions
        Permission::findOrCreate('payment.view', 'web');
        Permission::findOrCreate('payment.capture', 'web');
        $staffUser->givePermissionTo(['payment.view', 'payment.capture']);

        $this->getJson("/api/v1/admin/payments/{$paymentUuid}")->assertStatus(200);
        $this->postJson("/api/v1/admin/payments/{$paymentUuid}/capture", ['amount_minor' => 5000])->assertStatus(200);
    }
}
