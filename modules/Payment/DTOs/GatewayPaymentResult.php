<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

use Modules\Payment\Enums\PaymentTransactionStatus;

final readonly class GatewayPaymentResult
{
    public ?string $providerReference;

    public ?string $normalizedErrorCode;

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public PaymentTransactionStatus $status,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?PaymentActionDTO $action = null,
        public array $rawResponse = [],
        public ?string $providerResponseCode = null
    ) {
        $this->providerReference = $reference;
        $this->normalizedErrorCode = $errorCode;
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function success(string $reference, array $rawResponse = []): self
    {
        return new self(
            status: PaymentTransactionStatus::SUCCESS,
            reference: $reference,
            rawResponse: $rawResponse
        );
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function failure(string $errorCode, ?string $reference = null, array $rawResponse = []): self
    {
        return new self(
            status: PaymentTransactionStatus::FAILURE,
            reference: $reference,
            errorCode: $errorCode,
            rawResponse: $rawResponse
        );
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function actionRequired(PaymentActionDTO $action, ?string $reference = null, array $rawResponse = []): self
    {
        return new self(
            status: PaymentTransactionStatus::ACTION_REQUIRED,
            reference: $reference,
            action: $action,
            rawResponse: $rawResponse
        );
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function unknown(string $errorCode = 'unknown_timeout', ?string $reference = null, array $rawResponse = []): self
    {
        return new self(
            status: PaymentTransactionStatus::UNKNOWN,
            reference: $reference,
            errorCode: $errorCode,
            rawResponse: $rawResponse
        );
    }
}
