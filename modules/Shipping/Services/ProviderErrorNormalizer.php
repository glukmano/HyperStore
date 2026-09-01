<?php

declare(strict_types=1);

namespace Modules\Shipping\Services;

use Modules\Shipping\ValueObjects\ProviderError;
use Throwable;

class ProviderErrorNormalizer
{
    /**
     * Normalizes any raw throwable into a sanitized, safe ProviderError DTO.
     * Guaranteed never to leak raw exception messages, tokens, credentials, or payload fragments.
     */
    public function normalize(
        Throwable $exception,
        string $carrierCode,
        string $providerCode,
        ?string $correlationId = null,
        ?int $latencyMs = null
    ): ProviderError {
        $rawMessage = strtolower($exception->getMessage());
        $errorCode = 'provider_internal_error';
        $safeMessage = 'Carrier rate calculation service encountered an internal error.';
        $isRetryable = false;

        if (str_contains($rawMessage, 'timeout') || str_contains($rawMessage, 'timed out') || str_contains($rawMessage, '504')) {
            $errorCode = 'timeout';
            $safeMessage = 'Carrier rate request timed out.';
            $isRetryable = true;
        } elseif (str_contains($rawMessage, 'unauthorized') || str_contains($rawMessage, 'forbidden') || str_contains($rawMessage, 'auth') || str_contains($rawMessage, '401') || str_contains($rawMessage, '403')) {
            $errorCode = 'authentication_error';
            $safeMessage = 'Carrier authentication failed.';
            $isRetryable = false;
        } elseif (str_contains($rawMessage, 'network') || str_contains($rawMessage, 'connection') || str_contains($rawMessage, '502') || str_contains($rawMessage, '503')) {
            $errorCode = 'network_error';
            $safeMessage = 'Carrier service network connection error.';
            $isRetryable = true;
        } elseif (str_contains($rawMessage, 'address') || str_contains($rawMessage, 'postal') || str_contains($rawMessage, 'zip') || str_contains($rawMessage, 'destination')) {
            $errorCode = 'invalid_address';
            $safeMessage = 'Carrier cannot ship to the specified destination address.';
            $isRetryable = false;
        } elseif (str_contains($rawMessage, 'unavailable') || str_contains($rawMessage, 'not available')) {
            $errorCode = 'unavailable_service';
            $safeMessage = 'Requested carrier service is currently unavailable.';
            $isRetryable = true;
        }

        return new ProviderError(
            carrierCode: $carrierCode,
            providerCode: $providerCode,
            errorCode: $errorCode,
            safeMessage: $safeMessage,
            isRetryable: $isRetryable,
            correlationId: $correlationId,
            latencyMs: $latencyMs
        );
    }
}
