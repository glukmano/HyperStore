<?php

declare(strict_types=1);

namespace Modules\Checkout\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class SelectedShippingQuote
{
    /**
     * @param  array<string, mixed>  $breakdown
     */
    public function __construct(
        public int $methodId,
        public string $methodCode,
        public ?string $carrierCode,
        public ?string $serviceCode,
        public MoneyValue $originalAmount,
        public MoneyValue $finalAmount,
        public string $fingerprint,
        public array $breakdown = []
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $currency): self
    {
        $originalMinor = (int) ($data['original_amount'] ?? $data['original_amount_minor'] ?? 0);
        $finalMinor = (int) ($data['final_amount'] ?? $data['final_amount_minor'] ?? 0);

        return new self(
            methodId: (int) $data['method_id'],
            methodCode: (string) $data['method_code'],
            carrierCode: isset($data['carrier_code']) ? (string) $data['carrier_code'] : null,
            serviceCode: isset($data['service_code']) ? (string) $data['service_code'] : null,
            originalAmount: MoneyValue::fromMinor($originalMinor, $currency),
            finalAmount: MoneyValue::fromMinor($finalMinor, $currency),
            fingerprint: (string) ($data['fingerprint'] ?? ''),
            breakdown: (array) ($data['breakdown'] ?? [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method_id' => $this->methodId,
            'method_code' => $this->methodCode,
            'carrier_code' => $this->carrierCode,
            'service_code' => $this->serviceCode,
            'original_amount' => $this->originalAmount->getMinorAmount(),
            'final_amount' => $this->finalAmount->getMinorAmount(),
            'fingerprint' => $this->fingerprint,
            'breakdown' => $this->breakdown,
        ];
    }
}
