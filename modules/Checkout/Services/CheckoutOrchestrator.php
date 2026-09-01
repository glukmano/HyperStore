<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use App\Core\Channels\Contracts\StoreChannelEligibilityInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\DTOs\CheckoutReadyResult;
use Modules\Checkout\DTOs\SelectedShippingQuote;
use Modules\Checkout\Models\CheckoutSession;
use RuntimeException;

class CheckoutOrchestrator implements CheckoutOrchestratorInterface
{
    public function __construct(
        private readonly CheckoutStateMachineService $stateMachine,
        private readonly CheckoutPricingOrchestrator $pricingOrchestrator,
        private readonly CheckoutShippingOrchestrator $shippingOrchestrator,
        private readonly CheckoutInventoryReservationOrchestrator $reservationOrchestrator,
        private readonly CheckoutIdempotencyService $idempotencyService,
        private readonly StoreChannelEligibilityInterface $channelEligibility
    ) {}

    public function createFromCart(Cart $cart, ?string $idempotencyKey = null): CheckoutSession
    {
        if (! $cart->isActive()) {
            throw new RuntimeException("Cannot initiate checkout from inactive or expired Cart [{$cart->id}].");
        }

        if ($cart->lines()->count() === 0) {
            throw new RuntimeException("Cannot initiate checkout from empty Cart [{$cart->id}].");
        }

        // Validate StoreChannel eligibility
        if (! $this->channelEligibility->isEnabledForStore($cart->store_id, $cart->channel_id)) {
            throw new InvalidArgumentException("Channel [{$cart->channel_id}] is not enabled for Store [{$cart->store_id}].");
        }

        $payload = ['cart_id' => $cart->id, 'cart_version' => $cart->version];

        $res = $this->idempotencyService->execute(
            tenantId: $cart->tenant_id,
            cartId: $cart->id,
            checkoutSessionId: null,
            operationType: 'create_checkout',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($cart) {
                // Check if active non-terminal checkout already exists
                /** @var CheckoutSession|null $existing */
                $existing = CheckoutSession::query()
                    ->where('tenant_id', $cart->tenant_id)
                    ->where('cart_id', $cart->id)
                    ->whereNotIn('state', ['ready_for_order', 'expired', 'cancelled', 'failed'])
                    ->first();

                if ($existing !== null) {
                    return ['session_id' => $existing->id];
                }

                $session = CheckoutSession::create([
                    'tenant_id' => $cart->tenant_id,
                    'cart_id' => $cart->id,
                    'user_id' => $cart->user_id,
                    'guest_token_hash' => $cart->guest_token_hash,
                    'store_id' => $cart->store_id,
                    'market_id' => $cart->market_id,
                    'channel_id' => $cart->channel_id,
                    'currency' => $cart->currency,
                    'locale' => $cart->locale,
                    'state' => 'created',
                    'evaluated_cart_version' => $cart->version,
                ]);

                return ['session_id' => $session->id];
            }
        );

        return CheckoutSession::query()->where('id', $res['session_id'])->with('cart.lines.product')->firstOrFail();
    }

    public function setCustomerData(CheckoutSession $session, CheckoutCustomerData $data, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        $payload = $data->toArray();

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'customer_data',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session, $data) {
                return DB::transaction(function () use ($session, $data) {
                    $session->refresh();
                    $session->customer_data = $data->toArray();

                    if ($session->state === 'created') {
                        $this->stateMachine->assertCanTransition($session, 'customer_info_ready');
                        $session->state = 'customer_info_ready';
                    }

                    $session->version++;
                    $session->save();

                    return ['session_id' => $session->id];
                });
            }
        );

        $session->refresh();

        return $session;
    }

    public function setAddresses(
        CheckoutSession $session,
        CheckoutAddress $shippingAddress,
        ?CheckoutAddress $billingAddress = null,
        ?string $idempotencyKey = null
    ): CheckoutSession {
        $this->assertFreshCart($session);

        $billing = $billingAddress ?? $shippingAddress;
        $payload = [
            'shipping' => $shippingAddress->toArray(),
            'billing' => $billing->toArray(),
        ];

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'addresses',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session, $shippingAddress, $billing) {
                return DB::transaction(function () use ($session, $shippingAddress, $billing) {
                    $session->refresh();
                    $session->shipping_address = $shippingAddress->toArray();
                    $session->billing_address = $billing->toArray();

                    // Invalidate previous shipping quote & taxes on destination change
                    $session->selected_shipping_quote = null;

                    // Recalculate pricing and taxes
                    $pricingRes = $this->pricingOrchestrator->calculate($session->cart, $shippingAddress, null);
                    $session->pricing_snapshot = $pricingRes['pricing_snapshot'];
                    $session->tax_snapshot = $pricingRes['tax_snapshot'];
                    $session->promotion_snapshot = $pricingRes['promotion_snapshot'];

                    if (in_array($session->state, ['created', 'customer_info_ready'], true)) {
                        $this->stateMachine->assertCanTransition($session, 'address_ready');
                        $session->state = 'address_ready';
                    }

                    $session->version++;
                    $session->save();

                    return ['session_id' => $session->id];
                });
            }
        );

        $session->refresh();

        return $session;
    }

    public function selectShippingQuote(CheckoutSession $session, array $rateQuoteData, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        if ($session->shipping_address === null) {
            throw new InvalidArgumentException('Shipping address must be set before selecting a shipping quote.');
        }

        $dest = CheckoutAddress::fromArray($session->shipping_address);
        $payload = $rateQuoteData;

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'shipping_selection',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session, $dest, $rateQuoteData) {
                return DB::transaction(function () use ($session, $dest, $rateQuoteData) {
                    $session->refresh();

                    $quoteRes = $this->shippingOrchestrator->quote($session->cart, $dest);
                    $selectedQuote = $this->shippingOrchestrator->buildSelectedQuote(
                        $session->cart,
                        $dest,
                        $rateQuoteData,
                        $quoteRes['fulfillment_plan']
                    );

                    $session->selected_shipping_quote = $selectedQuote->toArray();

                    // Recalculate pricing with shipping
                    $pricingRes = $this->pricingOrchestrator->calculate($session->cart, $dest, $selectedQuote);
                    $session->pricing_snapshot = $pricingRes['pricing_snapshot'];
                    $session->tax_snapshot = $pricingRes['tax_snapshot'];
                    $session->promotion_snapshot = $pricingRes['promotion_snapshot'];

                    if (in_array($session->state, ['address_ready', 'fulfillment_ready'], true)) {
                        $this->stateMachine->assertCanTransition($session, 'shipping_ready');
                        $session->state = 'shipping_ready';
                    }

                    $session->version++;
                    $session->save();

                    return ['session_id' => $session->id];
                });
            }
        );

        $session->refresh();

        return $session;
    }

    public function reserveInventory(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        return DB::transaction(function () use ($session) {
            /** @var CheckoutSession $lockedSession */
            $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshCart($lockedSession);

            $dest = $lockedSession->shipping_address !== null
                ? CheckoutAddress::fromArray($lockedSession->shipping_address)
                : new CheckoutAddress(recipient: 'Customer', streetLines: ['Main St'], city: 'Zurich', countryCode: 'CH');

            $quoteRes = $this->shippingOrchestrator->quote($lockedSession->cart, $dest);
            $plan = $quoteRes['fulfillment_plan'];

            $acquiredIds = $this->reservationOrchestrator->reserve($lockedSession, $plan);

            $lockedSession->reservation_references = $acquiredIds;

            if (in_array($lockedSession->state, ['shipping_ready', 'fulfillment_ready', 'address_ready', 'customer_info_ready'], true)) {
                $this->stateMachine->assertCanTransition($lockedSession, 'inventory_reserved');
                $lockedSession->state = 'inventory_reserved';
            }

            $lockedSession->version++;
            $lockedSession->save();

            return $lockedSession;
        });
    }

    public function recalculate(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutSession
    {
        $session->refresh();
        $this->assertFreshCart($session);

        $dest = $session->shipping_address !== null ? CheckoutAddress::fromArray($session->shipping_address) : null;
        $quote = $session->selected_shipping_quote !== null ? SelectedShippingQuote::fromArray($session->selected_shipping_quote, $session->currency) : null;

        $pricingRes = $this->pricingOrchestrator->calculate($session->cart, $dest, $quote);

        $session->pricing_snapshot = $pricingRes['pricing_snapshot'];
        $session->tax_snapshot = $pricingRes['tax_snapshot'];
        $session->promotion_snapshot = $pricingRes['promotion_snapshot'];
        $session->version++;
        $session->save();

        return $session;
    }

    public function markReadyForOrder(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutReadyResult
    {
        $this->assertFreshCart($session);

        $payload = ['checkout_id' => $session->id, 'version' => $session->version];

        $res = $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'ready_for_order',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session) {
                return DB::transaction(function () use ($session) {
                    /** @var CheckoutSession $locked */
                    $locked = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                    $this->assertFreshCart($locked);

                    if ($locked->ready_snapshot !== null) {
                        return $locked->ready_snapshot;
                    }

                    $this->stateMachine->assertCanTransition($locked, 'ready_for_order');

                    $dest = $locked->shipping_address !== null ? CheckoutAddress::fromArray($locked->shipping_address) : null;
                    $quote = $locked->selected_shipping_quote !== null ? SelectedShippingQuote::fromArray($locked->selected_shipping_quote, $locked->currency) : null;

                    $pricingRes = $this->pricingOrchestrator->calculate($locked->cart, $dest, $quote);

                    $readyResult = new CheckoutReadyResult(
                        checkoutSessionId: $locked->id,
                        checkoutUuid: $locked->uuid,
                        tenantId: $locked->tenant_id,
                        cartId: $locked->cart_id,
                        cartVersion: $locked->evaluated_cart_version,
                        context: [
                            'store_id' => $locked->store_id,
                            'market_id' => $locked->market_id,
                            'channel_id' => $locked->channel_id,
                            'currency' => $locked->currency,
                            'locale' => $locked->locale,
                        ],
                        customerData: $locked->customer_data ?? [],
                        shippingAddress: $locked->shipping_address,
                        billingAddress: $locked->billing_address,
                        lines: $locked->cart->lines->map(fn ($l) => [
                            'product_id' => $l->product_id,
                            'variant_id' => $l->variant_id,
                            'quantity' => $l->quantity,
                        ])->toArray(),
                        totals: $pricingRes['totals']->toArray(),
                        pricingSnapshot: $pricingRes['pricing_snapshot'],
                        taxSnapshot: $pricingRes['tax_snapshot'],
                        promotionSnapshot: $pricingRes['promotion_snapshot'],
                        fulfillmentSnapshot: null,
                        selectedShippingQuote: $locked->selected_shipping_quote,
                        reservationReferences: $locked->reservation_references ?? [],
                        state: 'ready_for_order',
                        finalizedAt: now()
                    );

                    $locked->state = 'ready_for_order';
                    $locked->ready_snapshot = $readyResult->toArray();
                    $locked->version++;
                    $locked->save();

                    return $readyResult->toArray();
                });
            }
        );

        return new CheckoutReadyResult(
            checkoutSessionId: (int) $res['checkout_session_id'],
            checkoutUuid: (string) $res['checkout_uuid'],
            tenantId: (int) $res['tenant_id'],
            cartId: (int) $res['cart_id'],
            cartVersion: (int) $res['cart_version'],
            context: (array) $res['context'],
            customerData: (array) $res['customer_data'],
            shippingAddress: $res['shipping_address'],
            billingAddress: $res['billing_address'],
            lines: (array) $res['lines'],
            totals: (array) $res['totals'],
            pricingSnapshot: $res['pricing_snapshot'],
            taxSnapshot: $res['tax_snapshot'],
            promotionSnapshot: $res['promotion_snapshot'],
            fulfillmentSnapshot: $res['fulfillment_snapshot'],
            selectedShippingQuote: $res['selected_shipping_quote'],
            reservationReferences: (array) $res['reservation_references'],
            state: (string) $res['state'],
            finalizedAt: Carbon::parse((string) $res['finalized_at'])
        );
    }

    public function cancel(CheckoutSession $session, ?string $idempotencyKey = null): bool
    {
        return DB::transaction(function () use ($session) {
            $session->refresh();
            if ($session->isTerminal()) {
                return true;
            }

            $this->reservationOrchestrator->releaseAll($session);

            $session->state = 'cancelled';
            $session->version++;

            return $session->save();
        });
    }

    private function assertFreshCart(CheckoutSession $session): void
    {
        $cart = $session->cart()->first();
        if ($cart === null || $session->evaluated_cart_version !== $cart->version) {
            throw new RuntimeException("CART_STALE: CheckoutSession was evaluated against Cart version [{$session->evaluated_cart_version}], but Cart is now version [{$cart?->version}].");
        }
    }
}
