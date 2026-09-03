<?php

declare(strict_types=1);

namespace Modules\Ledger\DTOs;

use Modules\Ledger\Enums\JournalDirection;

final readonly class JournalLineDTO
{
    public string $directionValue;

    public function __construct(
        public int $accountId,
        JournalDirection|string $direction,
        public int $amountMinor,
        public string $currency,
        public ?string $description = null
    ) {
        $this->directionValue = $direction instanceof JournalDirection ? $direction->value : $direction;
    }
}
