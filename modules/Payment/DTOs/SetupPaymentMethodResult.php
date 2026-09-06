<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

final readonly class SetupPaymentMethodResult
{
    public function __construct(
        public bool $success,
        public ?string $providerReference = null,
        public ?string $displayBrand = null,
        public ?string $displayLast4 = null,
        public ?string $errorCode = null
    ) {}

    public static function success(string $providerReference, ?string $displayBrand = null, ?string $displayLast4 = null): self
    {
        return new self(success: true, providerReference: $providerReference, displayBrand: $displayBrand, displayLast4: $displayLast4);
    }

    public static function failure(string $errorCode): self
    {
        return new self(success: false, errorCode: $errorCode);
    }
}
