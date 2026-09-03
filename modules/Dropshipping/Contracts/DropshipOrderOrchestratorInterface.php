<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Contracts;

use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Fulfillment\Models\OrderFulfillment;

interface DropshipOrderOrchestratorInterface
{
    public function createPurchaseOrderForFulfillment(OrderFulfillment $fulfillment): PurchaseOrder;
}
