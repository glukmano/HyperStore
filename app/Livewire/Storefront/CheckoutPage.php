<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentActionType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Services\PaymentInitiationService;
use RuntimeException;
use Throwable;

class CheckoutPage extends Component
{
    public string $step = 'customer';

    public ?int $checkoutSessionId = null;

    public string $email = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $phone = '';

    public string $recipient = '';

    public string $street = '';

    public string $city = '';

    public string $postalCode = '';

    public string $countryCode = '';

    public ?string $selectedRateId = null;

    /** @var array<string, mixed> */
    public array $shippingRates = [];

    public string $paymentMethodType = 'card';

    public ?int $placedOrderId = null;

    public ?int $placedOrderTenantId = null;

    public ?string $placedOrderNumber = null;

    public ?int $placedOrderAmountMinor = null;

    public ?string $placedOrderCurrency = null;

    /** @var array<string, mixed>|null */
    public ?array $paymentResult = null;

    public ?string $paymentErrorMessage = null;

    public function startCheckout(CartServiceInterface $cartService, CheckoutOrchestratorInterface $orchestrator): void
    {
        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();

        if ($tenantId === null || $storeId === null) {
            session()->flash('error', 'A store context is required to check out.');

            return;
        }

        $cart = $cartService->getOrCreateActiveCart(new CartContext(
            tenantId: (int) $tenantId,
            storeId: (int) $storeId,
            marketId: (int) ($context->getMarket()->getId() ?? 0),
            channelId: (int) ($context->getChannel()->getId() ?? 0),
            currency: $context->getCurrency()->getCode() ?? 'USD',
            locale: app()->getLocale(),
            userId: is_int(auth()->id()) ? auth()->id() : null,
            guestToken: session()->getId(),
        ));

        if ($cart->lines->count() === 0) {
            session()->flash('error', 'Your cart is empty.');
            $this->redirect(route('storefront.cart'), navigate: true);

            return;
        }

        $session = $orchestrator->createFromCart($cart);
        $this->checkoutSessionId = $session->id;
        $this->step = 'customer';
    }

    public function submitCustomer(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
        ]);

        $session = $this->requireSession();
        $orchestrator->setCustomerData($session, new CheckoutCustomerData(
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            phone: $this->phone !== '' ? $this->phone : null,
        ));

        $this->step = 'address';
    }

    public function submitAddress(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->validate([
            'recipient' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'countryCode' => ['required', 'string', 'size:2'],
        ]);

        $session = $this->requireSession();
        $address = CheckoutAddress::fromArray([
            'recipient' => $this->recipient,
            'street_lines' => [$this->street],
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'postal_code' => $this->postalCode,
        ]);

        $session = $orchestrator->setAddresses($session, $address);
        $this->shippingRates = $orchestrator->getShippingRates($session);
        $this->step = 'shipping';
    }

    public function submitShipping(CheckoutOrchestratorInterface $orchestrator): void
    {
        if ($this->selectedRateId === null) {
            session()->flash('error', 'Please select a shipping method.');

            return;
        }

        $session = $this->requireSession();
        $rate = collect($this->shippingRates)->firstWhere('id', $this->selectedRateId)
            ?? collect($this->shippingRates)->first() ?? [];

        $session = $orchestrator->selectShippingQuote($session, is_array($rate) ? $rate : []);
        $orchestrator->reserveInventory($session);
        $this->step = 'payment';
    }

    /**
     * Places the order (existing OrderCreationServiceInterface::createFromCheckout() call,
     * unchanged) and then initiates payment against the newly created order via
     * PaymentInitiationService::initiatePayment() (Modules\Payment\Services\PaymentInitiationService::initiatePayment(),
     * modules/Payment/Services/PaymentInitiationService.php:49). The Order must exist before
     * payment can be initiated — PaymentInitiationService::executeInitiation() loads the Order
     * by id and validates amount/currency against it (modules/Payment/Services/PaymentInitiationService.php:80-91) —
     * so order creation always happens first, exactly once per checkout session.
     */
    public function placeOrder(
        CheckoutOrchestratorInterface $orchestrator,
        OrderCreationServiceInterface $orderCreation,
        PaymentInitiationService $paymentInitiation
    ): void {
        if ($this->placedOrderId === null) {
            $session = $this->requireSession();
            $ready = $orchestrator->markReadyForOrder($session);

            $result = $orderCreation->createFromCheckout(new OrderCreationDTO(
                tenantId: $ready->tenantId,
                checkoutId: $ready->checkoutSessionId,
            ));

            $order = $result->order;
            $this->placedOrderId = $order->id;
            $this->placedOrderTenantId = $order->tenant_id;
            $this->placedOrderNumber = $order->order_number;
            $this->placedOrderAmountMinor = $order->grand_total_minor;
            $this->placedOrderCurrency = $order->currency;
        }

        $this->initiatePaymentForPlacedOrder($paymentInitiation);
    }

    /**
     * Re-attempts payment initiation for the order already created by placeOrder() above,
     * without re-creating the order. Wired to the retry action shown on a failed payment.
     */
    public function retryPayment(PaymentInitiationService $paymentInitiation): void
    {
        if ($this->placedOrderId === null) {
            return;
        }

        $this->initiatePaymentForPlacedOrder($paymentInitiation);
    }

    private function initiatePaymentForPlacedOrder(PaymentInitiationService $paymentInitiation): void
    {
        if ($this->placedOrderId === null || $this->placedOrderTenantId === null
            || $this->placedOrderAmountMinor === null || $this->placedOrderCurrency === null) {
            throw new RuntimeException('Order must be placed before payment can be initiated.');
        }

        $this->paymentErrorMessage = null;

        try {
            $response = $paymentInitiation->initiatePayment(new InitiatePaymentDTO(
                tenantId: $this->placedOrderTenantId,
                orderId: $this->placedOrderId,
                amountMinor: $this->placedOrderAmountMinor,
                currency: $this->placedOrderCurrency,
                providerCode: null,
                paymentMethodType: $this->paymentMethodType !== '' ? $this->paymentMethodType : null,
                paymentMethodReference: null,
                captureImmediately: true,
                idempotencyKey: null,
                metadata: [],
            ));
        } catch (Throwable) {
            $this->paymentResult = null;
            $this->paymentErrorMessage = 'Payment could not be initiated. Please try again.';
            $this->step = 'payment_failed';

            return;
        }

        $this->paymentResult = $response;
        $this->branchOnPaymentResponse($response);
    }

    /**
     * Branches ONLY on the fields PaymentInitiationService::initiatePayment() already returns
     * (via PaymentTransactionReconciliationService::formatResponse(),
     * modules/Payment/Services/PaymentTransactionReconciliationService.php:281-298) — no new
     * action_type/status semantics are decided here. An unrecognized status is never treated
     * as success.
     *
     * @param  array<string, mixed>  $response
     */
    private function branchOnPaymentResponse(array $response): void
    {
        $status = $response['status'] ?? null;
        $actionType = $response['action_type'] ?? null;
        $normalizedErrorCode = $response['normalized_error_code'] ?? null;

        if (in_array($status, [PaymentStatus::CAPTURED->value, PaymentStatus::AUTHORIZED->value], true)) {
            $this->redirect(route('storefront.order-confirmation', ['orderNumber' => $this->placedOrderNumber]), navigate: true);

            return;
        }

        if ($actionType === PaymentActionType::REDIRECT_URL->value) {
            $payload = $response['action_payload'] ?? null;
            $url = is_array($payload) ? ($payload['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                $this->redirect($url);

                return;
            }
        }

        if ($actionType === PaymentActionType::CLIENT_SECRET->value || $actionType === PaymentActionType::QR_CODE->value) {
            $this->step = 'payment_action';

            return;
        }

        if ($normalizedErrorCode !== null) {
            $this->step = 'payment_failed';

            return;
        }

        // Status is neither a recognized success nor a recognized failure/action —
        // never guess. Surface a neutral "processing" state instead.
        $this->step = 'payment_processing';
    }

    public function render(): View
    {
        $session = $this->checkoutSessionId !== null ? CheckoutSession::find($this->checkoutSessionId) : null;

        return view('theme::pages.checkout', [
            'session' => $session,
        ])->layout('theme::layouts.app', ['title' => 'Checkout']);
    }

    private function requireSession(): CheckoutSession
    {
        $session = $this->checkoutSessionId !== null ? CheckoutSession::find($this->checkoutSessionId) : null;

        if ($session === null) {
            throw new RuntimeException('Checkout session not found. Please start again.');
        }

        return $session;
    }
}
