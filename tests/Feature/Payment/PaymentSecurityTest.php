<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\GatewayPaymentRequest;
use Modules\Payment\DTOs\GatewayPaymentResult;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Services\PaymentInitiationService;
use RuntimeException;
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

    public function test_true_guest_order_access_and_isolation(): void
    {
        $rawToken = 'secret-guest-token-xyz-98765';
        $tokenHash = hash('sha256', $rawToken);

        // True guest: userId is explicitly null, no Sanctum actingAs
        $order = $this->createGuestOrder(
            grandTotalMinor: 5000,
            currency: 'EUR',
            guestTokenHash: $tokenHash
        );
        $this->assertNull($order->user_id);

        // 1. Unauthenticated guest with valid token: success
        $this->flushHeaders();
        $initResponse = $this->withHeader('X-Order-Token', $rawToken)
            ->postJson("/api/v1/orders/{$order->uuid}/payments", [
                'amount_minor' => 5000,
                'currency' => 'EUR',
            ]);
        $initResponse->assertStatus(201);

        $this->flushHeaders();
        $viewResponse = $this->withHeader('X-Order-Token', $rawToken)
            ->getJson("/api/v1/orders/{$order->uuid}/payments");
        $viewResponse->assertStatus(200);

        // 2. Unauthenticated guest with missing token: 403
        $this->flushHeaders();
        $noTokenResponse = $this->getJson("/api/v1/orders/{$order->uuid}/payments");
        $noTokenResponse->assertStatus(403);

        // 3. Unauthenticated guest with wrong token: 403
        $this->flushHeaders();
        $wrongTokenResponse = $this->withHeader('X-Order-Token', 'invalid-token-string')
            ->getJson("/api/v1/orders/{$order->uuid}/payments");
        $wrongTokenResponse->assertStatus(403);

        // 4. Token for another order: 403
        $this->flushHeaders();
        $otherOrder = $this->createGuestOrder(
            grandTotalMinor: 5000,
            currency: 'EUR',
            guestTokenHash: hash('sha256', 'another-token')
        );
        $otherOrderResponse = $this->withHeader('X-Order-Token', $rawToken)
            ->getJson("/api/v1/orders/{$otherOrder->uuid}/payments");
        $otherOrderResponse->assertStatus(403);

        // 5. Invariant: Raw guest token is absent from database records
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertStringNotContainsString($rawToken, (string) json_encode($payment->toArray()));

        $transactions = PaymentTransaction::where('payment_id', $payment->id)->get();
        foreach ($transactions as $tx) {
            $this->assertStringNotContainsString($rawToken, (string) json_encode($tx->toArray()));
        }

        $opKeys = PaymentOperationKey::where('order_id', $order->id)->get();
        foreach ($opKeys as $key) {
            $this->assertStringNotContainsString($rawToken, (string) json_encode($key->toArray()));
        }
    }

    public function test_api_responses_do_not_expose_internal_database_integer_ids(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', userId: $this->user->id);
        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/orders/{$order->uuid}/payments", [
            'amount_minor' => 5000,
            'currency' => 'EUR',
        ])->assertStatus(201);

        $viewResponse = $this->getJson("/api/v1/orders/{$order->uuid}/payments");
        $viewResponse->assertStatus(200);

        $json = $viewResponse->json();
        $this->assertArrayHasKey('data', $json);
        $data = $json['data'];

        // Invariant: NO internal database integer IDs
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('order_id', $data);

        // Must expose public UUIDs
        $this->assertArrayHasKey('uuid', $data);
        $this->assertArrayHasKey('order_uuid', $data);
        $this->assertSame($order->uuid, $data['order_uuid']);

        // Transactions list check
        if (! empty($data['transactions'])) {
            foreach ($data['transactions'] as $tx) {
                $this->assertArrayNotHasKey('id', $tx);
                $this->assertArrayNotHasKey('payment_id', $tx);
                $this->assertArrayHasKey('uuid', $tx);
            }
        }

        // Numeric database order ID rejected on public routes
        $this->getJson("/api/v1/orders/{$order->id}/payments")->assertStatus(404);
    }

    public function test_sensitive_exception_strings_are_never_persisted_or_leaked(): void
    {
        $sensitiveString = 'api_secret_key_12345_leaked';

        // Mock gateway to throw an exception with this sensitive string
        /** @var PaymentGatewayRegistryInterface $registry */
        $registry = app(PaymentGatewayRegistryInterface::class);
        $mockGateway = new class($sensitiveString) extends FakePaymentGateway
        {
            public function __construct(private readonly string $secret) {}

            public function getProviderCode(): string
            {
                return 'mock_leak';
            }

            public function purchase(GatewayPaymentRequest $request): GatewayPaymentResult
            {
                throw new RuntimeException("Fatal error: {$this->secret}");
            }
        };
        $registry->register($mockGateway);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', userId: $this->user->id);

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'mock_leak',
            idempotencyKey: 'idem_leak_test'
        );

        $service = app(PaymentInitiationService::class);
        $result = $service->initiatePayment($dto);

        // Result returned should have normalized error code
        $this->assertSame('failure', $result['transaction_status']);
        $this->assertSame('gateway_error', $result['normalized_error_code']);
        $this->assertStringNotContainsString($sensitiveString, (string) json_encode($result));

        // Verify database: payment_transactions
        $transactions = PaymentTransaction::where('tenant_id', $this->tenant->id)->get();
        foreach ($transactions as $tx) {
            $this->assertStringNotContainsString($sensitiveString, (string) json_encode($tx->toArray()));
        }

        // Verify database: payment_operation_keys
        $opKeys = PaymentOperationKey::where('tenant_id', $this->tenant->id)->get();
        foreach ($opKeys as $key) {
            $this->assertStringNotContainsString($sensitiveString, (string) json_encode($key->toArray()));
        }
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
