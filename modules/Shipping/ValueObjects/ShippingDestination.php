<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class ShippingDestination
{
    public string $countryCode;

    public ?string $regionCode;

    public ?string $city;

    public ?string $postalCode;

    public ?string $addressLine1;

    public ?string $addressLine2;

    public function __construct(
        string $countryCode,
        ?string $regionCode = null,
        ?string $city = null,
        ?string $postalCode = null,
        ?string $addressLine1 = null,
        ?string $addressLine2 = null
    ) {
        $this->countryCode = strtoupper(trim($countryCode));
        $this->regionCode = $regionCode !== null ? strtoupper(trim($regionCode)) : null;
        $this->city = $city !== null ? trim($city) : null;
        $this->postalCode = $postalCode !== null ? trim($postalCode) : null;
        $this->addressLine1 = $addressLine1 !== null ? trim($addressLine1) : null;
        $this->addressLine2 = $addressLine2 !== null ? trim($addressLine2) : null;
    }

    /**
     * Normalized postal code for uppercase matching without extraneous whitespace.
     */
    public function getNormalizedPostalCode(): ?string
    {
        if ($this->postalCode === null) {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', '', $this->postalCode) ?? $this->postalCode);
    }
}
