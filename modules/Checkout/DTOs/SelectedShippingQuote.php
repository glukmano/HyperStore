<?php

declare(strict_types=1);

namespace Modules\Checkout\DTOs;

use Carbon\Carbon;
use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class SelectedShippingQuote
{
    /**
     * @param  array<string, mixed>  $breakdown
     * @param  array<string, mixed>  $rateRelevantInputs
     */
    public function __construct(
        public int $methodId,
        public string $methodCode,
        public ?string $carrierCode,
        public ?string $serviceCode,
        public MoneyValue $originalAmount,
        public MoneyValue $finalAmount,
        public string $fingerprint,
        public Carbon $quotedAt,
        public Carbon $expiresAt,
        public array $breakdown = [],
        public array $rateRelevantInputs = []
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }

    /**
     * Computes complete rate-relevant fingerprint covering all pricing, fulfillment, and destination inputs.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function computeFingerprint(array $inputs): string
    {
        ksort($inputs);

        return hash('sha256', (string) json_encode($inputs));
    }

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
            quotedAt: isset($data['quoted_at']) ? Carbon::parse((string) $data['quoted_at']) : now(),
            expiresAt: isset($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : now()->addMinutes(30),
            breakdown: (array) ($data['breakdown'] ?? []),
            rateRelevantInputs: (array) ($data['rate_relevant_inputs'] ?? [])
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
            'quoted_at' => $this->quotedAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'breakdown' => $this->breakdown,
            'rate_relevant_inputs' => $this->rateRelevantInputs,
        ];
    }
}
