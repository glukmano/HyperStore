<?php

declare(strict_types=1);

namespace Modules\GiftCards\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Modules\Customers\Services\CustomerProfileService;
use Modules\GiftCards\Exceptions\GiftCardException;
use Modules\GiftCards\Services\GiftCardService;
use Modules\Wallet\Contracts\StoreValueServiceInterface;

/**
 * Owner Delta D.48/D.55: brute-force code-guessing is mitigated by
 * rate-limiting this endpoint (per-IP throttle, Laravel's own built-in
 * limiter — no new package) and the code's own high entropy.
 */
class RedeemGiftCardWidget extends Component
{
    public string $code = '';

    public ?string $redeemError = null;

    public ?string $redeemSuccessMessage = null;

    public function redeem(GiftCardService $giftCardService, CustomerProfileService $profileService): void
    {
        $this->redeemError = null;
        $this->redeemSuccessMessage = null;

        $throttleKey = 'gift-card-redeem:'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $this->redeemError = 'Too many attempts. Please try again later.';

            return;
        }
        RateLimiter::hit($throttleKey, 300);

        $this->validate(['code' => 'required|string|min:6|max:64']);

        /** @var User|null $user */
        $user = auth()->user();
        $customerProfileId = $user !== null ? $profileService->firstOrCreateFor($user)->id : null;

        try {
            $tenantId = (int) app(ContextManager::class)->getTenant()->getId();
            $account = $giftCardService->resolveAccountForCode($tenantId, trim($this->code), $customerProfileId);
            $balance = app(StoreValueServiceInterface::class)->getAvailableBalanceMinor($account);

            $this->redeemSuccessMessage = 'Gift Card redeemed. Balance: '.number_format($balance / 100, 2).' '.$account->currency;
            $this->code = '';
        } catch (GiftCardException $e) {
            $this->redeemError = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('gift-cards::livewire.storefront.redeem-gift-card-widget');
    }
}
