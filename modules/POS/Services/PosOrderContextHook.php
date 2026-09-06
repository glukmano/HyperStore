<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Modules\Order\Models\Order;
use Modules\POS\Contracts\PosOrderContextHookInterface;
use Modules\POS\Enums\RegisterSessionStatus;
use Modules\POS\Exceptions\PosOrderContextException;
use Modules\POS\Models\PosRegister;
use Modules\POS\Models\PosRegisterSession;

/**
 * Owner Delta §2: hard-fail, never a try/catch soft hook. Called from
 * OrderCreationService::createFromCheckout() without exception handling —
 * any PosOrderContextException here rolls back the entire Order-creation
 * transaction.
 */
final class PosOrderContextHook implements PosOrderContextHookInterface
{
    public function __construct(
        private readonly PosCashierAuthorizationService $authorizationService,
    ) {}

    public function validateAndFreezePosContext(Order $order): void
    {
        if ($order->pos_register_session_id === null) {
            return;
        }

        /** @var PosRegisterSession|null $session */
        $session = PosRegisterSession::where('tenant_id', $order->tenant_id)
            ->where('id', $order->pos_register_session_id)
            ->first();

        if ($session === null || $session->status !== RegisterSessionStatus::ACTIVE) {
            throw new PosOrderContextException(
                "Order [{$order->id}] references RegisterSession [{$order->pos_register_session_id}] which is missing or not active."
            );
        }

        /** @var PosRegister|null $register */
        $register = PosRegister::where('tenant_id', $order->tenant_id)
            ->where('id', $order->pos_register_id)
            ->first();

        if ($register === null || (int) $register->id !== (int) $session->register_id) {
            throw new PosOrderContextException("Order [{$order->id}] Register/RegisterSession mismatch.");
        }

        if ($order->cashier_user_id === null || ! $this->authorizationService->isAuthorizedForRegister((int) $order->cashier_user_id, $register)) {
            throw new PosOrderContextException("Cashier is not authorized for Register [{$register->id}].");
        }

        $storeMarket = $register->storeMarket;
        if ($storeMarket === null) {
            throw new PosOrderContextException("Register [{$register->id}] has no resolvable StoreMarket.");
        }

        $storeId = $storeMarket->store_id;
        if ((int) $order->store_id !== (int) $storeId) {
            throw new PosOrderContextException(
                "Order [{$order->id}] Store [{$order->store_id}] does not match Register's Store [{$storeId}]."
            );
        }

        if (empty($order->receipt_number)) {
            $order->receipt_number = $this->generateReceiptNumber($register);
            $order->save();
        }
    }

    private function generateReceiptNumber(PosRegister $register): string
    {
        return sprintf('%s-%s', strtoupper($register->code), now()->format('ymdHis').random_int(100, 999));
    }
}
