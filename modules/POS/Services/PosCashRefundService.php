<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Modules\Customers\Services\CustomerProfileService;
use Modules\Order\Models\Order;
use Modules\POS\Contracts\PosCashRefundServiceInterface;
use Modules\POS\Enums\CashMovementType;
use Modules\POS\Enums\RegisterSessionStatus;
use Modules\POS\Models\PosRegister;
use Modules\POS\Models\PosRegisterSession;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Services\StoreValueService;

/**
 * Owner Delta §5: cash refund is owned by POS, not by Store Value. If an
 * open RegisterSession exists for the Order's Store, the refund is a
 * compensating refund_cash_out drawer movement. Otherwise — a
 * Control-Center-initiated refund of a cash-tendered Order with no open
 * register — the explicit, documented fallback is Store Credit (cash
 * cannot be refunded from a screen with no physical drawer behind it).
 */
final class PosCashRefundService implements PosCashRefundServiceInterface
{
    public function __construct(
        private readonly PosCashMovementService $cashMovementService,
        private readonly StoreValueService $storeValueService,
        private readonly CustomerProfileService $customerProfileService,
    ) {}

    public function refundCash(Order $order, int $amountMinor, string $refundEventUuid): array
    {
        $registerIds = PosRegister::where('tenant_id', $order->tenant_id)
            ->whereHas('storeMarket', function ($q) use ($order): void {
                $q->where('store_id', $order->store_id);
            })
            ->pluck('id');

        /** @var PosRegisterSession|null $session */
        $session = PosRegisterSession::where('tenant_id', $order->tenant_id)
            ->whereIn('register_id', $registerIds)
            ->where('status', RegisterSessionStatus::ACTIVE->value)
            ->first();

        if ($session !== null) {
            $this->cashMovementService->record(
                session: $session,
                type: CashMovementType::REFUND_CASH_OUT,
                magnitudeMinor: $amountMinor,
                sourceType: 'order_refund',
                sourceUuid: $refundEventUuid,
                createdByUserId: $session->cashier_user_id,
                reason: "Cash refund for Order [{$order->order_number}]"
            );

            return ['method' => 'cash', 'register_session_id' => $session->id];
        }

        // Fallback: Store Credit — no open register to record a physical
        // cash-out movement against.
        $user = $order->user_id !== null ? $order->user : null;
        if ($user !== null) {
            $customerProfile = $this->customerProfileService->firstOrCreateFor($user);
            $account = $this->storeValueService->findOrCreateAccount(
                $order->tenant_id,
                $customerProfile->id,
                StoreValueInstrumentType::StoreCredit,
                $order->currency
            );

            $this->storeValueService->refundCredit($account, $amountMinor, "cash_fallback:{$refundEventUuid}");
        }

        return ['method' => 'store_credit_fallback', 'register_session_id' => null];
    }
}
