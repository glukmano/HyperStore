<?php

declare(strict_types=1);

namespace Modules\Checkout\DTOs;

final readonly class CheckoutCustomerData
{
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone = null,
        public ?string $vatId = null
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            email: trim((string) ($data['email'] ?? '')),
            firstName: trim((string) ($data['first_name'] ?? '')),
            lastName: trim((string) ($data['last_name'] ?? '')),
            phone: isset($data['phone']) ? trim((string) $data['phone']) : null,
            vatId: isset($data['vat_id']) ? trim((string) $data['vat_id']) : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'vat_id' => $this->vatId,
        ];
    }
}
