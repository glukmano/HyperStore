<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

use Modules\Payment\Enums\ReconciliationStatus;

final readonly class GatewayReconciliationResult
{
    public ?string $providerReference;

    public ?string $normalizedErrorCode;

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public ReconciliationStatus $status,
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
            status: ReconciliationStatus::SUCCESS,
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
            status: ReconciliationStatus::FAILURE,
            reference: $reference,
            errorCode: $errorCode,
            rawResponse: $rawResponse
        );
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function stillPending(array $rawResponse = []): self
    {
        return new self(
            status: ReconciliationStatus::STILL_PENDING,
            rawResponse: $rawResponse
        );
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public static function unknown(string $errorCode = 'reconciliation_indeterminate', ?string $reference = null, array $rawResponse = []): self
    {
        return new self(
            status: ReconciliationStatus::UNKNOWN,
            reference: $reference,
            errorCode: $errorCode,
            rawResponse: $rawResponse
        );
    }
}
