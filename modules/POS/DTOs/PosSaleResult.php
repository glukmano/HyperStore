<?php

declare(strict_types=1);

namespace Modules\POS\DTOs;

use Modules\Order\Models\Order;
use Modules\POS\Models\PosReceipt;

final readonly class PosSaleResult
{
    public function __construct(
        public Order $order,
        public PosReceipt $receipt,
        public bool $isReplay,
    ) {}
}
