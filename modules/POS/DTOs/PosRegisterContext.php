<?php

declare(strict_types=1);

namespace Modules\POS\DTOs;

final readonly class PosRegisterContext
{
    public function __construct(
        public int $tenantId,
        public int $storeId,
        public int $marketId,
        public int $channelId,
        public int $inventorySourceId,
        /** @var list<string> */
        public array $allowedCurrencies,
    ) {}
}
