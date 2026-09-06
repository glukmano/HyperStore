<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

use Modules\Payment\DTOs\GatewayOffSessionChargeRequest;
use Modules\Payment\DTOs\GatewayPaymentResult;
use Modules\Payment\DTOs\SetupPaymentMethodRequest;
use Modules\Payment\DTOs\SetupPaymentMethodResult;

/**
 * Owner Delta §9/§10: an OPTIONAL capability interface — the base
 * PaymentGatewayInterface is never modified, and every existing gateway
 * implementation continues to compile/function unchanged. A caller detects
 * recurring capability via `$gateway instanceof RecurringPaymentGatewayInterface`;
 * a gateway that does not implement it is simply, correctly, "not
 * recurring-capable" — never a fake/simulated generic fallback.
 */
interface RecurringPaymentGatewayInterface extends PaymentGatewayInterface
{
    /**
     * Attaches/tokenizes a reusable payment method and returns an opaque
     * provider reference — never raw card data.
     */
    public function setupPaymentMethod(SetupPaymentMethodRequest $request): SetupPaymentMethodResult;

    /**
     * Charges a previously-tokenized payment method reference later,
     * off-session. The caller supplies its own provider idempotency key
     * (computed at billing-period claim time — see
     * SubscriptionRenewalAttempt) so a retried HTTP call is safe at the
     * provider's own layer too, not only at ours.
     */
    public function chargeOffSession(GatewayOffSessionChargeRequest $request): GatewayPaymentResult;
}
