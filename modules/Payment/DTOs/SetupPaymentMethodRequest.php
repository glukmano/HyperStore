<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

final readonly class SetupPaymentMethodRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $tenantId,
        public int $customerProfileId,
        public string $paymentMethodType,
        public string $paymentMethodReference,
        public array $metadata = []
    ) {}
}
