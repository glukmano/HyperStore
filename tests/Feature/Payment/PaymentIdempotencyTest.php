<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Database\QueryException;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Exceptions\PaymentIdempotencyConflictException;
use Modules\Payment\Exceptions\PaymentOperationInProgressException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->service = app(PaymentInitiationService::class);

        /** @var PaymentGatewayRegistryInterface $registry */
        $registry = app(PaymentGatewayRegistryInterface::class);
        /** @var FakePaymentGateway $fake */
        $fake = $registry->get('fake');
        $fake->reset();
    }

    public function test_same_idempotency_key_with_identical_payload_replays_cached_response(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            idempotencyKey: 'idem_replay_test'
        );

        $result1 = $this->service->initiatePayment($dto);
        $result2 = $this->service->initiatePayment($dto);

        $this->assertSame($result1['payment_uuid'], $result2['payment_uuid']);
        $this->assertSame($result1['transaction_uuid'], $result2['transaction_uuid']);

        // DB asserts: exactly one Payment and one Transaction exist
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame(1, PaymentTransaction::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, PaymentOperationKey::where('idempotency_key', 'idem_replay_test')->count());
    }

    public function test_same_idempotency_key_with_differing_payload_throws_conflict_exception(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto1 = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodType: 'card',
            idempotencyKey: 'idem_conflict_test'
        );

        $this->service->initiatePayment($dto1);

        $dto2 = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodType: 'bank_transfer', // conflicting parameter
            idempotencyKey: 'idem_conflict_test'
        );

        $this->expectException(PaymentIdempotencyConflictException::class);
        $this->service->initiatePayment($dto2);
    }

    public function test_active_lease_throws_payment_operation_in_progress_exception_without_polling(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            idempotencyKey: 'idem_in_progress_test'
        );

        // Pre-create an active lease currently held by another worker
        $requestHash = hash('sha256', (string) json_encode([
            'tenant_id' => $dto->tenantId,
            'order_id' => $dto->orderId,
            'amount_minor' => $dto->amountMinor,
            'currency' => strtoupper($dto->currency),
            'provider_code' => $dto->providerCode,
            'payment_method_type' => $dto->paymentMethodType,
            'payment_method_reference' => $dto->paymentMethodReference,
            'capture_immediately' => $dto->captureImmediately,
            'metadata' => $dto->metadata,
        ]));

        PaymentOperationKey::create([
            'tenant_id' => $this->tenant->id,
            'idempotency_key' => 'idem_in_progress_test',
            'operation_type' => 'initiate_payment',
            'order_id' => $order->id,
            'payment_id' => null,
            'request_hash' => $requestHash,
            'status' => 'started',
            'lease_expires_at' => now()->addMinute(),
        ]);

        // Second call with same active lease MUST throw PaymentOperationInProgressException immediately without polling
        $this->expectException(PaymentOperationInProgressException::class);
        $this->expectExceptionMessage('is currently in progress');

        $this->service->initiatePayment($dto);
    }

    public function test_expired_lease_is_safely_reclaimed_by_subsequent_request(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            idempotencyKey: 'idem_expired_lease_test'
        );

        $requestHash = hash('sha256', (string) json_encode([
            'tenant_id' => $dto->tenantId,
            'order_id' => $dto->orderId,
            'amount_minor' => $dto->amountMinor,
            'currency' => strtoupper($dto->currency),
            'provider_code' => $dto->providerCode,
            'payment_method_type' => $dto->paymentMethodType,
            'payment_method_reference' => $dto->paymentMethodReference,
            'capture_immediately' => $dto->captureImmediately,
            'metadata' => $dto->metadata,
        ]));

        // Pre-create an EXPIRED lease
        PaymentOperationKey::create([
            'tenant_id' => $this->tenant->id,
            'idempotency_key' => 'idem_expired_lease_test',
            'operation_type' => 'initiate_payment',
            'order_id' => $order->id,
            'payment_id' => null,
            'request_hash' => $requestHash,
            'status' => 'started',
            'lease_expires_at' => now()->subMinutes(5), // expired
        ]);

        // Subsequent call safely reclaims the lease and completes
        $result = $this->service->initiatePayment($dto);

        $this->assertSame('captured', $result['status']);
        $this->assertSame('success', $result['transaction_status']);

        $opKey = PaymentOperationKey::where('idempotency_key', 'idem_expired_lease_test')->firstOrFail();
        $this->assertSame('completed', $opKey->status);
    }

    public function test_grep_proves_zero_usleep_or_sleep_in_modules_payment(): void
    {
        $modulesPath = base_path('modules/Payment');

        $output1 = shell_exec("grep -rn 'usleep' {$modulesPath}");
        $output2 = shell_exec("grep -rn 'sleep(' {$modulesPath}");

        $this->assertEmpty(trim((string) $output1), 'Found forbidden usleep in modules/Payment');
        $this->assertEmpty(trim((string) $output2), 'Found forbidden sleep() in modules/Payment');
    }

    public function test_database_uniqueness_prevents_duplicate_transactions_for_same_operation_key(): void
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

        /** @var PaymentOperationKey $opKey */
        $opKey = PaymentOperationKey::create([
            'tenant_id' => $this->tenant->id,
            'idempotency_key' => 'idem_db_tx_unique',
            'operation_type' => 'initiate_payment',
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'request_hash' => 'dummy-hash',
            'status' => 'completed',
        ]);

        // First transaction for opKey
        PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'payment_operation_key_id' => $opKey->id,
            'operation_type' => PaymentOperationType::PURCHASE->value,
            'status' => PaymentTransactionStatus::SUCCESS->value,
            'amount_minor' => 5000,
            'currency' => 'EUR',
        ]);

        // Attempting to insert a second transaction for the same opKey MUST violate UNIQUE(payment_operation_key_id)
        $this->expectException(QueryException::class);
        PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'payment_operation_key_id' => $opKey->id,
            'operation_type' => PaymentOperationType::PURCHASE->value,
            'status' => PaymentTransactionStatus::SUCCESS->value,
            'amount_minor' => 5000,
            'currency' => 'EUR',
        ]);
    }

    public function test_database_uniqueness_prevents_duplicate_provider_idempotency_identities(): void
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

        // First transaction with provider_idempotency_key
        PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => PaymentOperationType::PURCHASE->value,
            'status' => PaymentTransactionStatus::SUCCESS->value,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'hyp_tx_unique_key_123',
        ]);

        // Second transaction with identical provider_idempotency_key MUST fail via DB unique constraint
        $this->expectException(QueryException::class);
        PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => PaymentOperationType::PURCHASE->value,
            'status' => PaymentTransactionStatus::SUCCESS->value,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'hyp_tx_unique_key_123',
        ]);
    }
}
