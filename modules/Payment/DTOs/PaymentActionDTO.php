<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

use Modules\Payment\Enums\PaymentActionType;

final readonly class PaymentActionDTO
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public PaymentActionType $type,
        public array $payload
    ) {}

    /**
     * @return array{type: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'payload' => $this->payload,
        ];
    }
}
