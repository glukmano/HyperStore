<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Models\SellerReturn;

interface ReturnRefundOrchestratorInterface
{
    public function finalizeRefund(int $tenantId, int $sellerReturnId): SellerReturn;
}
