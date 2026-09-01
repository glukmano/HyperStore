<?php

declare(strict_types=1);

namespace Modules\Checkout\Contracts;

use Modules\Cart\Models\Cart;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\DTOs\CheckoutReadyResult;
use Modules\Checkout\Models\CheckoutSession;

interface CheckoutOrchestratorInterface
{
    public function createFromCart(Cart $cart, ?string $idempotencyKey = null): CheckoutSession;

    public function setCustomerData(CheckoutSession $session, CheckoutCustomerData $customerData, ?string $idempotencyKey = null): CheckoutSession;

    public function setAddresses(CheckoutSession $session, CheckoutAddress $shippingAddress, ?CheckoutAddress $billingAddress = null, ?string $idempotencyKey = null): CheckoutSession;

    /**
     * @param  array<string, mixed>  $rateQuoteData
     */
    public function selectShippingQuote(CheckoutSession $session, array $rateQuoteData, ?string $idempotencyKey = null): CheckoutSession;

    public function reserveInventory(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutSession;

    public function recalculate(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutSession;

    public function markReadyForOrder(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutReadyResult;

    public function cancel(CheckoutSession $session, ?string $idempotencyKey = null): bool;
}
