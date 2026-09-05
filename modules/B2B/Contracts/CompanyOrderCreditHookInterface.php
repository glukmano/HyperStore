<?php

declare(strict_types=1);

namespace Modules\B2B\Contracts;

use Modules\B2B\Exceptions\InsufficientCompanyCreditException;
use Modules\Order\Models\Order;

/**
 * Owner Delta §2: the exact credit-reservation boundary — invoked from
 * INSIDE `OrderCreationService::executeOrderCreationTransaction()`'s own
 * `DB::transaction()`, after the Order row exists (so its uuid/
 * grand_total_minor are available) and BEFORE that transaction commits.
 * Unlike Affiliate's attribution hook, an `InsufficientCompanyCreditException`
 * thrown here is NEVER swallowed by the caller — it must propagate and roll
 * back the entire Order-creation transaction, so no Order can ever survive
 * a failed credit reservation.
 */
interface CompanyOrderCreditHookInterface
{
    /**
     * @throws InsufficientCompanyCreditException
     */
    public function applyCompanyContextAndReserveCredit(Order $order): void;
}
