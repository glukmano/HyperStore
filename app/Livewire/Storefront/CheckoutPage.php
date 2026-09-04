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
        $this->step = 'review';
    }

    public function placeOrder(CheckoutOrchestratorInterface $orchestrator, OrderCreationServiceInterface $orderCreation): void
    {
        $session = $this->requireSession();
        $ready = $orchestrator->markReadyForOrder($session);

        $result = $orderCreation->createFromCheckout(new OrderCreationDTO(
            tenantId: $ready->tenantId,
            checkoutId: $ready->checkoutSessionId,
        ));

        $this->redirect(route('storefront.order-confirmation', ['orderNumber' => $result->order->order_number]), navigate: true);
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
            throw new \RuntimeException('Checkout session not found. Please start again.');
        }

        return $session;
    }
}
