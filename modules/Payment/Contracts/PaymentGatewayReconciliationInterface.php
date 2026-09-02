<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

use Modules\Payment\DTOs\GatewayReconciliationRequest;
use Modules\Payment\DTOs\GatewayReconciliationResult;

interface PaymentGatewayReconciliationInterface
{
    /**
     * Determine if this gateway driver supports out-of-band transaction reconciliation.
     */
    public function supportsReconciliation(): bool;

    /**
     * Inquire the remote provider for the authoritative status of a prior operation
     * without re-executing any monetary transfer.
     */
    public function reconcileOperation(GatewayReconciliationRequest $request): GatewayReconciliationResult;
}
