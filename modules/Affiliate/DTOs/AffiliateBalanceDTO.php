<?php

declare(strict_types=1);

namespace Modules\Affiliate\DTOs;

final readonly class AffiliateBalanceDTO
{
    public function __construct(
        public int $pendingBalanceMinor,
        public int $heldBalanceMinor,
        public int $availableEconomicBalanceMinor,
        public int $reservedForPayoutMinor,
        public int $withdrawableBalanceMinor,
        public string $currency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pending_balance_minor' => $this->pendingBalanceMinor,
            'held_balance_minor' => $this->heldBalanceMinor,
            'available_economic_balance_minor' => $this->availableEconomicBalanceMinor,
            'reserved_for_payout_minor' => $this->reservedForPayoutMinor,
            'withdrawable_balance_minor' => $this->withdrawableBalanceMinor,
            'currency' => $this->currency,
        ];
    }
}
