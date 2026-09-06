<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\OrderActorType;
use Modules\Order\Models\Order;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\POS\DTOs\PosSaleResult;
use Modules\POS\DTOs\PosTenderSelection;
use Modules\POS\Enums\CashMovementType;
use Modules\POS\Exceptions\PosRegisterSessionException;
use Modules\POS\Models\PosReceipt;
use Modules\POS\Models\PosRegister;
use Modules\POS\Models\PosRegisterSession;

/**
 * Owner Delta §14: reuses the ENTIRE existing Cart -> Checkout -> Order ->
 * Payment pipeline — no second commerce core. A completed POS sale is
 * always, structurally, an ordinary Order.
 *
 * Owner Delta §11: sale idempotency is DB-backed via the EXISTING
 * OrderCreationService idempotency-key mechanism — the POS idempotency key
 * is `pos_sale:{tenant_id}:{register_session_id}:{client_sale_id}`.
 */
final class PosSaleOrchestrator
{
    public function __construct(
        private readonly CartServiceInterface $cartService,
        private readonly CheckoutOrchestratorInterface $checkoutOrchestrator,
        private readonly OrderCreationServiceInterface $orderCreationService,
        private readonly PaymentInitiationService $paymentInitiationService,
        private readonly PosCashMovementService $cashMovementService,
        private readonly PosRegisterContextResolver $contextResolver,
    ) {}

    private function requireRegister(PosRegisterSession $session): PosRegister
    {
        $register = $session->register;
        if ($register === null) {
            throw new PosRegisterSessionException("RegisterSession [{$session->id}] has no resolvable Register.");
        }

        return $register;
    }

    public function getOrCreateCart(PosRegisterSession $session, ?int $userId, ?string $guestToken = null): Cart
    {
        $register = $this->requireRegister($session);
        $context = $this->contextResolver->resolve($register);

        return $this->cartService->getOrCreateActiveCart(new CartContext(
            tenantId: $context->tenantId,
            storeId: $context->storeId,
            marketId: $context->marketId,
            channelId: $context->channelId,
            currency: $session->currency,
            userId: $userId,
            guestToken: $guestToken,
        ));
    }

    public function completeSale(
        PosRegisterSession $session,
        Cart $cart,
        string $clientSaleId,
        PosTenderSelection $tender,
        ?CheckoutAddress $storeAddress = null,
        CheckoutCustomerData $customerData = new CheckoutCustomerData('walk-in@pos.local', 'Walk-in', 'Customer'),
    ): PosSaleResult {
        if (! $session->isActive()) {
            throw new PosRegisterSessionException("RegisterSession [{$session->id}] is not active.");
        }

        $idempotencyKey = "pos_sale:{$session->tenant_id}:{$session->id}:{$clientSaleId}";

        $checkout = $this->checkoutOrchestrator->createFromCart($cart, "{$idempotencyKey}:create");

        // Owner Delta §11: a retried/duplicate submission of the same
        // client_sale_id must return the SAME Order — never re-run the
        // Checkout pipeline against an already-finalized session (which
        // would legitimately conflict on Checkout's own per-step
        // idempotency payloads once the session's version has moved on).
        $existingOrder = Order::where('tenant_id', $session->tenant_id)->where('checkout_id', $checkout->id)->first();
        if ($existingOrder !== null) {
            return new PosSaleResult($existingOrder, $this->getOrCreateReceipt($session, $existingOrder), true);
        }

        $this->checkoutOrchestrator->setCustomerData($checkout, $customerData, "{$idempotencyKey}:customer");

        // The Checkout state machine requires an address before inventory
        // can be reserved (needed for tax nexus resolution too) — a POS
        // take-with-you sale still needs SOME address; default to the
        // register's own Store as a reasonable point-of-sale nexus when
        // the caller does not supply one explicitly.
        $this->checkoutOrchestrator->setAddresses(
            $checkout,
            $storeAddress ?? new CheckoutAddress('Walk-in Customer', ['In-Store Sale'], 'N/A', 'US'),
            null,
            "{$idempotencyKey}:address"
        );

        // A raw, targeted column update — never a full Eloquent model
        // save() here, which could race with/clobber the Checkout
        // pipeline's own version-tracked mutations on the same row.
        DB::table('checkout_sessions')->where('id', $checkout->id)->update([
            'pos_context_snapshot' => json_encode([
                'register_id' => $session->register_id,
                'register_session_id' => $session->id,
                'cashier_user_id' => $session->cashier_user_id,
                'client_sale_id' => $clientSaleId,
            ]),
        ]);
        $checkout->refresh();

        // The Checkout state machine requires a selected shipping quote
        // before it will accept inventory_reserved for a physical cart
        // (address_ready -> shipping_ready -> inventory_reserved). A POS
        // take-with-you sale still goes through this same, unmodified
        // machinery — it simply selects whatever rate resolves (a Tenant
        // is expected to configure a $0 in-store/pickup shipping method
        // for its POS Store, exactly as it would for BOPIS).
        $rates = $this->checkoutOrchestrator->getShippingRates($checkout);
        $quotes = $rates['shipping_result']->quotes ?? collect();
        if ($quotes->isNotEmpty()) {
            $cheapest = $quotes->first();
            $this->checkoutOrchestrator->selectShippingQuote(
                $checkout,
                ['method_id' => $cheapest->methodId, 'method_code' => $cheapest->methodCode],
                "{$idempotencyKey}:shipping"
            );
        }

        $this->checkoutOrchestrator->reserveInventory($checkout, "{$idempotencyKey}:reserve");

        if ($tender->usesStoreValue()) {
            $this->checkoutOrchestrator->applyStoreValue(
                $checkout,
                (string) $tender->storeValueInstrumentType,
                $tender->storeValueAccountUuid,
                (int) $tender->storeValueRequestedAmountMinor,
                "{$idempotencyKey}:store_value"
            );
        }

        $readyResult = $this->checkoutOrchestrator->markReadyForOrder($checkout, "{$idempotencyKey}:ready");

        $orderResult = $this->orderCreationService->createFromCheckout(new OrderCreationDTO(
            tenantId: $session->tenant_id,
            checkoutId: $readyResult->checkoutSessionId,
            idempotencyKey: $idempotencyKey,
            actorType: OrderActorType::STAFF,
            actorId: $session->cashier_user_id,
        ));

        $order = $orderResult->order;
        $amountDueMinor = (int) ($order->amount_due_minor ?? $order->grand_total_minor);

        if (! $orderResult->isReplay && $amountDueMinor > 0) {
            $this->settlePayment($session, $order, $amountDueMinor, $tender, $idempotencyKey);
            $order = $order->fresh() ?? $order;
        }

        $receipt = $this->getOrCreateReceipt($session, $order);

        return new PosSaleResult($order, $receipt, $orderResult->isReplay);
    }

    private function settlePayment(PosRegisterSession $session, Order $order, int $amountDueMinor, PosTenderSelection $tender, string $idempotencyKey): void
    {
        if ($tender->usesCash()) {
            $this->paymentInitiationService->initiateCashPayment(new InitiatePaymentDTO(
                tenantId: $order->tenant_id,
                orderId: $order->id,
                amountMinor: $amountDueMinor,
                currency: $order->currency,
                idempotencyKey: "{$idempotencyKey}:cash_payment",
                metadata: ['register_session_id' => $session->id, 'cashier_user_id' => $session->cashier_user_id],
            ));

            $this->cashMovementService->record(
                session: $session,
                type: CashMovementType::SALE_CASH_IN,
                magnitudeMinor: $amountDueMinor,
                sourceType: 'order_sale',
                sourceUuid: (string) $order->uuid,
                createdByUserId: $session->cashier_user_id,
                reason: "Cash sale for Order [{$order->order_number}]"
            );

            return;
        }

        if ($tender->usesCard()) {
            $this->paymentInitiationService->initiatePayment(new InitiatePaymentDTO(
                tenantId: $order->tenant_id,
                orderId: $order->id,
                amountMinor: $amountDueMinor,
                currency: $order->currency,
                providerCode: $tender->cardProviderCode,
                idempotencyKey: "{$idempotencyKey}:card_payment",
                metadata: ['register_session_id' => $session->id, 'cashier_user_id' => $session->cashier_user_id],
            ));
        }
    }

    private function getOrCreateReceipt(PosRegisterSession $session, Order $order): PosReceipt
    {
        /** @var PosReceipt|null $existing */
        $existing = PosReceipt::where('order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $register = $this->requireRegister($session);

        return PosReceipt::create([
            'tenant_id' => $order->tenant_id,
            'receipt_number' => (string) ($order->receipt_number ?? $order->order_number),
            'order_id' => $order->id,
            'register_id' => $register->id,
            'register_session_id' => $session->id,
            'cashier_user_id' => $session->cashier_user_id,
            'presentation_snapshot' => [
                'register_code' => $register->code,
                'register_name' => $register->name,
                'cashier_display_name' => $session->cashier->name ?? 'Cashier',
            ],
        ]);
    }
}
