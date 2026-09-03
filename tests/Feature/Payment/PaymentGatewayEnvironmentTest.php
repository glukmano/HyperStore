<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Exceptions\GatewayUnavailableException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\PaymentServiceProvider;
use Modules\Payment\Registries\PaymentGatewayRegistry;
use Modules\Payment\Services\PaymentCancellationService;
use Modules\Payment\Services\PaymentCaptureService;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Payment\Services\PaymentRefundService;
use Modules\Payment\Services\PaymentTransactionReconciliationService;
use Tests\TestCase;

class PaymentGatewayEnvironmentTest extends TestCase
{
    use PaymentTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
    }

    public function test_fake_payment_gateway_is_not_registered_in_production(): void
    {
        $app = clone app();
        $app->detectEnvironment(fn () => 'production');

        $provider = new PaymentServiceProvider($app);
        $provider->register();

        /** @var PaymentGatewayRegistryInterface $registry */
        $registry = $app->make(PaymentGatewayRegistryInterface::class);

        $this->assertFalse($registry->has('fake'), 'FakePaymentGateway must NEVER be registered in production.');
        $this->assertFalse($registry->hasDefault(), 'No default provider should exist when no gateways are registered in production.');
    }

    public function test_production_with_no_providers_fails_closed(): void
    {
        // Bind an empty registry simulating production with no configured providers
        $emptyRegistry = new PaymentGatewayRegistry;
        $this->app->instance(PaymentGatewayRegistryInterface::class, $emptyRegistry);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: null // no provider selected
        );

        $service = app(PaymentInitiationService::class);

        $this->expectException(GatewayUnavailableException::class);
        $this->expectExceptionMessage('default');

        $service->initiatePayment($dto);
    }

    public function test_local_and_test_environments_have_fake_gateway_available(): void
    {
        /** @var PaymentGatewayRegistryInterface $registry */
        $registry = app(PaymentGatewayRegistryInterface::class);

        $this->assertTrue($registry->has('fake'));
        $this->assertTrue($registry->hasDefault());
        $this->assertSame('fake', $registry->default()->getProviderCode());
    }

    public function test_unknown_transaction_with_missing_provider_fails_closed(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'purchase',
            'status' => 'unknown',
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => null, // missing provider!
        ]);

        $reconciliationService = app(PaymentTransactionReconciliationService::class);

        $this->expectException(GatewayUnavailableException::class);
        $reconciliationService->reconcile($tx, $payment);
    }

    public function test_capture_refund_and_void_never_fallback_to_fake_when_provider_is_missing(): void
    {
        // Unset any default provider in an isolated registry
        $registryWithoutDefault = new PaymentGatewayRegistry;
        $this->app->instance(PaymentGatewayRegistryInterface::class, $registryWithoutDefault);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'authorized',
            'authorized_amount_minor' => 5000,
        ]);

        // Capture with no auth provider and no default provider
        $captureService = app(PaymentCaptureService::class);
        try {
            $captureService->capture($this->tenant->id, $payment->uuid, 5000);
            $this->fail('Expected GatewayUnavailableException for capture without provider');
        } catch (GatewayUnavailableException) {
            $this->assertTrue(true);
        }

        // Refund with no capture provider and no default provider
        $refundService = app(PaymentRefundService::class);
        $payment->status = 'captured';
        $payment->captured_amount_minor = 5000;
        $payment->save();

        try {
            $refundService->refund($this->tenant->id, $payment->uuid, 5000);
            $this->fail('Expected GatewayUnavailableException for refund without provider');
        } catch (GatewayUnavailableException) {
            $this->assertTrue(true);
        }

        // Void with no auth provider and no default provider
        $cancellationService = app(PaymentCancellationService::class);
        $payment->status = 'authorized';
        $payment->save();

        try {
            $cancellationService->cancel($this->tenant->id, $payment->uuid, 'Cancel reason');
            $this->fail('Expected GatewayUnavailableException for void without provider');
        } catch (GatewayUnavailableException) {
            $this->assertTrue(true);
        }
    }

    public function test_grep_audit_proves_no_production_silent_fake_fallback(): void
    {
        $servicesPath = base_path('modules/Payment/Services');

        $output = shell_exec("grep -rn \"?? 'fake'\" {$servicesPath}");
        $this->assertEmpty(trim((string) $output), "Found forbidden ?? 'fake' in modules/Payment/Services");

        $providerPath = base_path('modules/Payment/PaymentServiceProvider.php');
        $providerCode = file_get_contents($providerPath);
        $this->assertStringContainsString("app->environment('local', 'testing')", (string) $providerCode);
    }
}
