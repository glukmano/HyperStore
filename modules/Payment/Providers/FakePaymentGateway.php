<?php

declare(strict_types=1);

namespace Modules\Payment\Providers;

use Modules\Payment\Contracts\PaymentGatewayInterface;
use Modules\Payment\Contracts\PaymentGatewayReconciliationInterface;
use Modules\Payment\DTOs\GatewayCaptureRequest;
use Modules\Payment\DTOs\GatewayPaymentRequest;
use Modules\Payment\DTOs\GatewayPaymentResult;
use Modules\Payment\DTOs\GatewayReconciliationRequest;
use Modules\Payment\DTOs\GatewayReconciliationResult;
use Modules\Payment\DTOs\GatewayRefundRequest;
use Modules\Payment\DTOs\GatewayVoidRequest;
use Modules\Payment\DTOs\PaymentActionDTO;
use Modules\Payment\Enums\PaymentActionType;
use RuntimeException;

class FakePaymentGateway implements PaymentGatewayInterface, PaymentGatewayReconciliationInterface
{
    public const PROVIDER_CODE = 'fake';

    public int $monetaryExecutionCount = 0;

    public int $reconciliationCallCount = 0;

    public ?string $forcedNextOutcome = null;

    public function getProviderCode(): string
    {
        return self::PROVIDER_CODE;
    }

    public function supportsMethod(string $methodType): bool
    {
        return true;
    }

    public function supportsReconciliation(): bool
    {
        return true;
    }

    private function getStoragePath(string $key): string
    {
        return sys_get_temp_dir().'/fake_gateway_'.md5($key).'.json';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveRecord(string $key, array $data): void
    {
        file_put_contents($this->getStoragePath($key), json_encode($data));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRecord(string $key): ?array
    {
        $path = $this->getStoragePath($key);
        if (file_exists($path)) {
            $content = file_get_contents($path);

            return is_string($content) ? json_decode($content, true) : null;
        }

        return null;
    }

    public function purchase(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        $this->monetaryExecutionCount++;

        if ($this->forcedNextOutcome === 'timeout_after_success' || $request->paymentMethodReference === 'pm_timeout_after_success') {
            $reference = 'ch_fake_timeout_'.bin2hex(random_bytes(6));
            $this->saveRecord($request->providerIdempotencyKey, [
                'status' => 'success',
                'reference' => $reference,
                'amount' => $request->amountMinor,
                'currency' => $request->currency,
            ]);

            throw new RuntimeException('Simulated network timeout/disconnect after gateway processing.');
        }

        if ($this->forcedNextOutcome === 'decline' || $request->paymentMethodReference === 'pm_decline') {
            return GatewayPaymentResult::failure(
                errorCode: 'payment_declined',
                reference: 'ch_fake_declined_'.bin2hex(random_bytes(6))
            );
        }

        if ($this->forcedNextOutcome === 'action_required' || $request->paymentMethodReference === 'pm_3ds') {
            $reference = 'ch_fake_3ds_'.bin2hex(random_bytes(6));

            return GatewayPaymentResult::actionRequired(
                action: new PaymentActionDTO(
                    type: PaymentActionType::REDIRECT_URL,
                    payload: ['url' => 'https://checkout.fake-gateway.test/3ds/'.$reference]
                ),
                reference: $reference
            );
        }

        $reference = 'ch_fake_'.bin2hex(random_bytes(6));
        $this->saveRecord($request->providerIdempotencyKey, [
            'status' => 'success',
            'reference' => $reference,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        return GatewayPaymentResult::success(reference: $reference);
    }

    public function authorize(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        $this->monetaryExecutionCount++;

        if ($this->forcedNextOutcome === 'decline' || $request->paymentMethodReference === 'pm_decline') {
            return GatewayPaymentResult::failure(
                errorCode: 'authorization_declined',
                reference: 'auth_fake_declined_'.bin2hex(random_bytes(6))
            );
        }

        $reference = 'auth_fake_'.bin2hex(random_bytes(6));
        $this->saveRecord($request->providerIdempotencyKey, [
            'status' => 'authorized',
            'reference' => $reference,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        return GatewayPaymentResult::success(reference: $reference);
    }

    public function capture(GatewayCaptureRequest $request): GatewayPaymentResult
    {
        $this->monetaryExecutionCount++;
        $reference = 'cap_fake_'.bin2hex(random_bytes(6));

        $this->saveRecord($request->providerIdempotencyKey, [
            'status' => 'captured',
            'reference' => $reference,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        return GatewayPaymentResult::success(reference: $reference);
    }

    public function refund(GatewayRefundRequest $request): GatewayPaymentResult
    {
        $this->monetaryExecutionCount++;
        $reference = 'ref_fake_'.bin2hex(random_bytes(6));

        $this->saveRecord($request->providerIdempotencyKey, [
            'status' => 'refunded',
            'reference' => $reference,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        return GatewayPaymentResult::success(reference: $reference);
    }

    public function void(GatewayVoidRequest $request): GatewayPaymentResult
    {
        $reference = 'void_fake_'.bin2hex(random_bytes(6));

        $this->saveRecord($request->providerIdempotencyKey, [
            'status' => 'voided',
            'reference' => $reference,
            'amount' => 0,
            'currency' => '',
        ]);

        return GatewayPaymentResult::success(reference: $reference);
    }

    public function reconcileOperation(GatewayReconciliationRequest $request): GatewayReconciliationResult
    {
        $this->reconciliationCallCount++;

        if ($this->forcedNextOutcome === 'reconciliation_still_pending') {
            return GatewayReconciliationResult::stillPending();
        }

        if ($request->providerIdempotencyKey !== null) {
            $record = $this->getRecord($request->providerIdempotencyKey);
            if ($record !== null && ($record['status'] === 'success' || $record['status'] === 'captured' || $record['status'] === 'authorized')) {
                return GatewayReconciliationResult::success($record['reference']);
            }
        }

        return GatewayReconciliationResult::unknown('NO_REMOTE_MATCH');
    }

    public function reset(): void
    {
        $this->monetaryExecutionCount = 0;
        $this->reconciliationCallCount = 0;
        $this->forcedNextOutcome = null;
    }
}
