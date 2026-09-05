<?php

declare(strict_types=1);

namespace Modules\Promotions\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Customers\Services\CustomerProfileService;
use Modules\Promotions\Contracts\LoyaltyCheckoutRedemptionServiceInterface;
use Modules\Promotions\Exceptions\InsufficientLoyaltyPointsException;
use Modules\Promotions\Exceptions\NoLoyaltyCurrencyRuleException;
use Modules\Promotions\Services\LoyaltyService;

/**
 * Phase-19 Final Completion Delta §4 (CHECKOUT): the eligible-points
 * redemption control. Every figure shown (available balance, redemption
 * value) is recomputed server-side on every render — a client can never
 * supply/trust its own balance. redeemPoints() rejects a tampered/oversized
 * request with InsufficientLoyaltyPointsException; a missing currency rule
 * raises NoLoyaltyCurrencyRuleException — both are surfaced as plain
 * validation errors, never silently clamped.
 */
class LoyaltyCheckoutRedemption extends Component
{
    public int $checkoutSessionId;

    public int $pointsToRedeem = 0;

    public ?string $errorMessage = null;

    public function mount(int $checkoutSessionId): void
    {
        $this->checkoutSessionId = $checkoutSessionId;
    }

    private function session(): ?CheckoutSession
    {
        return CheckoutSession::find($this->checkoutSessionId);
    }

    private function currentRedemptionCode(CheckoutSession $session): ?string
    {
        $code = $session->cart->coupon_code;

        return ($code !== null && str_starts_with($code, 'LOYALTY-')) ? $code : null;
    }

    public function redeem(
        LoyaltyCheckoutRedemptionServiceInterface $redemptionService,
        CheckoutOrchestratorInterface $orchestrator,
        CustomerProfileService $profileService,
    ): void {
        $this->errorMessage = null;
        $session = $this->session();

        if ($session === null || ! auth()->check()) {
            return;
        }

        if ($this->pointsToRedeem <= 0) {
            $this->errorMessage = __('Enter how many points to redeem.');

            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $profile = $profileService->firstOrCreateFor($user);

        try {
            $coupon = $redemptionService->redeemForCheckout(
                customerProfile: $profile,
                tenantId: $session->tenant_id,
                points: $this->pointsToRedeem,
                currency: $session->currency,
                checkoutSessionUuid: $session->uuid,
            );

            $orchestrator->applyCoupon($session, $coupon->code);
        } catch (InsufficientLoyaltyPointsException|NoLoyaltyCurrencyRuleException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function cancelRedemption(
        LoyaltyCheckoutRedemptionServiceInterface $redemptionService,
        CheckoutOrchestratorInterface $orchestrator,
    ): void {
        $session = $this->session();
        if ($session === null) {
            return;
        }

        $orchestrator->removeCoupon($session);
        $redemptionService->cancelForCheckout($session->uuid, $session->tenant_id);
        $this->pointsToRedeem = 0;
    }

    public function render(LoyaltyService $loyaltyService, CustomerProfileService $profileService): View
    {
        $session = $this->session();

        if ($session === null || ! auth()->check()) {
            return view('promotions::livewire.loyalty-checkout-redemption', [
                'visible' => false,
                'available' => 0,
                'redemptionValueMinor' => null,
                'appliedCode' => null,
            ]);
        }

        /** @var User $user */
        $user = auth()->user();
        $profile = $profileService->firstOrCreateFor($user);

        $available = $loyaltyService->getAvailableBalance($profile);
        $redemptionValueMinor = $loyaltyService->redemptionValueForCurrency($session->tenant_id, $session->currency);

        return view('promotions::livewire.loyalty-checkout-redemption', [
            'visible' => $available > 0 && $redemptionValueMinor !== null,
            'available' => $available,
            'redemptionValueMinor' => $redemptionValueMinor,
            'appliedCode' => $this->currentRedemptionCode($session),
            'currency' => $session->currency,
        ]);
    }
}
