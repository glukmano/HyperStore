<?php

declare(strict_types=1);

namespace Modules\Checkout\DTOs;

use Modules\Shipping\ValueObjects\ShippingDestination;

final readonly class CheckoutAddress
{
    /**
     * @param  list<string>  $streetLines
     */
    public function __construct(
        public string $recipient,
        public array $streetLines,
        public string $city,
        public string $countryCode,
        public ?string $regionCode = null,
        public ?string $postalCode = null,
        public ?string $phone = null,
        public ?string $company = null
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $lines = $data['street_lines'] ?? [];
        if (is_string($lines)) {
            $lines = [$lines];
        }
        if (! is_array($lines)) {
            $lines = [];
        }

        return new self(
            recipient: trim((string) ($data['recipient'] ?? '')),
            streetLines: array_values(array_map('strval', $lines)),
            city: trim((string) ($data['city'] ?? '')),
            countryCode: strtoupper(trim((string) ($data['country_code'] ?? ''))),
            regionCode: isset($data['region_code']) ? strtoupper(trim((string) $data['region_code'])) : null,
            postalCode: isset($data['postal_code']) ? trim((string) $data['postal_code']) : null,
            phone: isset($data['phone']) ? trim((string) $data['phone']) : null,
            company: isset($data['company']) ? trim((string) $data['company']) : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recipient' => $this->recipient,
            'street_lines' => $this->streetLines,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'region_code' => $this->regionCode,
            'postal_code' => $this->postalCode,
            'phone' => $this->phone,
            'company' => $this->company,
        ];
    }

    public function toShippingDestination(): ShippingDestination
    {
        return new ShippingDestination(
            countryCode: $this->countryCode,
            regionCode: $this->regionCode,
            postalCode: $this->postalCode,
            city: $this->city
        );
    }
}
