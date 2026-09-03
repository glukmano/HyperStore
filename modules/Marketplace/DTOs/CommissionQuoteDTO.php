<?php

declare(strict_types=1);

namespace Modules\Marketplace\DTOs;

final readonly class CommissionQuoteDTO
{
    public function __construct(
        public int $basisMinor,
        public int $rateBps,
        public int $fixedFeeMinor,
        public int $commissionAmountMinor,
        public int $vendorNetAmountMinor,
        public string $currency,
        public string $ruleSource,
        public ?string $ruleReference = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commission_basis_minor' => $this->basisMinor,
            'commission_rate_bps' => $this->rateBps,
            'commission_fixed_fee_minor' => $this->fixedFeeMinor,
            'commission_amount_minor' => $this->commissionAmountMinor,
            'vendor_net_amount_minor' => $this->vendorNetAmountMinor,
            'commission_currency' => $this->currency,
            'commission_rule_source' => $this->ruleSource,
            'commission_rule_ref' => $this->ruleReference,
        ];
    }
}
