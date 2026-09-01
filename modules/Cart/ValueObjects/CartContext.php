<?php

declare(strict_types=1);

namespace Modules\Cart\ValueObjects;

final readonly class CartContext
{
    public function __construct(
        public int $tenantId,
        public int $storeId,
        public int $marketId,
        public int $channelId,
        public string $currency,
        public string $locale = 'en',
        public ?int $userId = null,
        public ?string $guestToken = null
    ) {}

    public function getGuestTokenHash(): ?string
    {
        if ($this->guestToken === null || trim($this->guestToken) === '') {
            return null;
        }

        return hash('sha256', trim($this->guestToken));
    }
}
