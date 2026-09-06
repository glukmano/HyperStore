<?php

declare(strict_types=1);

namespace Modules\POS\Contracts;

use Modules\Order\Models\Order;

/**
 * Owner Delta §2: POS Order context (register/session/cashier/store-market
 * origin) is operational and audit-critical, never a soft/optional
 * snapshot. This hook is invoked WITHOUT a try/catch from
 * OrderCreationService::createFromCheckout() — mirroring the B2B
 * CompanyOrderCreditHookInterface precedent exactly, never the Affiliate
 * soft-hook pattern. Any exception thrown here propagates and rolls back
 * the entire Order-creation transaction.
 */
interface PosOrderContextHookInterface
{
    /**
     * No-ops for a non-POS-originated Order (no pos_context_snapshot on the
     * checkout). For a POS-originated Order, re-validates the RegisterSession
     * is still active, the cashier is still authorized for the Register's
     * Store, and the Store/Market context still matches — then freezes the
     * immutable pos_register_id/pos_register_session_id/cashier_user_id
     * facts onto the Order. Throws on any live-validation failure.
     */
    public function validateAndFreezePosContext(Order $order): void;
}
