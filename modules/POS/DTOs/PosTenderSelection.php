<?php

declare(strict_types=1);

namespace Modules\POS\DTOs;

use Modules\POS\Exceptions\UnsupportedTenderCombinationException;

/**
 * Owner Delta §6: the exact Phase-22 mixed-tender policy. A POS Order may
 * use Store Value instrument(s) plus EXACTLY ONE settlement tender: cash
 * OR one external/card-present Payment. Cash + Card in the same sale is
 * explicitly deferred — there is no safe atomic cash+gateway settlement
 * protocol yet.
 */
final readonly class PosTenderSelection
{
    public function __construct(
        public ?int $cashAmountMinor = null,
        public ?string $cardProviderCode = null,
        public ?string $storeValueInstrumentType = null,
        public ?string $storeValueAccountUuid = null,
        public ?int $storeValueRequestedAmountMinor = null,
    ) {
        if ($this->cashAmountMinor !== null && $this->cardProviderCode !== null) {
            throw new UnsupportedTenderCombinationException(
                'Cash + Card in the same sale is not supported in Phase-22 — choose exactly one settlement tender.'
            );
        }
    }

    public function usesCash(): bool
    {
        return $this->cashAmountMinor !== null;
    }

    public function usesCard(): bool
    {
        return $this->cardProviderCode !== null;
    }

    public function usesStoreValue(): bool
    {
        return $this->storeValueInstrumentType !== null;
    }
}
