<?php

declare(strict_types=1);

namespace Modules\Wallet\Livewire\Storefront;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Customers\Services\CustomerProfileService;
use Modules\GiftCards\Exceptions\GiftCardException;
use Modules\GiftCards\Services\GiftCardService;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Models\StoreValueAccount;
use Throwable;

/**
 * D.49: at most one hold per instrument type present in one checkout —
 * this widget exposes exactly Wallet, Store Credit (the customer's own
 * existing accounts), and Gift Card (via a code-redemption field).
 */
class StoreValueApplyWidget extends Component
{
    public int $checkoutSessionId;

    public string $giftCardCode = '';

    public ?string $errorMessage = null;

    public function mount(int $checkoutSessionId): void
    {
        $this->checkoutSessionId = $checkoutSessionId;
    }

    private function session(): CheckoutSession
    {
        return CheckoutSession::findOrFail($this->checkoutSessionId);
    }

    public function applyWallet(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->apply($orchestrator, StoreValueInstrumentType::Wallet, null);
    }

    public function applyStoreCredit(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->apply($orchestrator, StoreValueInstrumentType::StoreCredit, null);
    }

    public function redeemGiftCard(CheckoutOrchestratorInterface $orchestrator, GiftCardService $giftCardService, CustomerProfileService $profileService): void
    {
        $this->errorMessage = null;

        /** @var User|null $user */
        $user = auth()->user();
        $customerProfileId = $user !== null ? $profileService->firstOrCreateFor($user)->id : null;

        try {
            $session = $this->session();
            $account = $giftCardService->resolveAccountForCode($session->tenant_id, trim($this->giftCardCode), $customerProfileId);
            $this->applyAccount($orchestrator, $account);
            $this->giftCardCode = '';
        } catch (GiftCardException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    private function apply(CheckoutOrchestratorInterface $orchestrator, StoreValueInstrumentType $type, ?string $accountUuid): void
    {
        $this->errorMessage = null;

        try {
            $session = $this->session();
            $orchestrator->applyStoreValue($session, $type->value, $accountUuid, PHP_INT_MAX);
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    private function applyAccount(CheckoutOrchestratorInterface $orchestrator, StoreValueAccount $account): void
    {
        $this->errorMessage = null;

        try {
            $session = $this->session();
            $orchestrator->applyStoreValue($session, $account->instrument_type->value, $account->uuid, PHP_INT_MAX);
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function remove(CheckoutOrchestratorInterface $orchestrator): void
    {
        $orchestrator->removeStoreValue($this->session());
    }

    public function render(CustomerProfileService $profileService, StoreValueServiceInterface $storeValueService): View
    {
        /** @var User|null $user */
        $user = auth()->user();

        $walletBalance = null;
        $storeCreditBalance = null;

        if ($user !== null) {
            $session = $this->session();
            $profile = $profileService->firstOrCreateFor($user);

            $walletAccount = StoreValueAccount::where('customer_profile_id', $profile->id)
                ->where('instrument_type', StoreValueInstrumentType::Wallet->value)
                ->where('currency', $session->currency)
                ->first();
            $storeCreditAccount = StoreValueAccount::where('customer_profile_id', $profile->id)
                ->where('instrument_type', StoreValueInstrumentType::StoreCredit->value)
                ->where('currency', $session->currency)
                ->first();

            $walletBalance = $walletAccount !== null ? $storeValueService->getAvailableBalanceMinor($walletAccount) : null;
            $storeCreditBalance = $storeCreditAccount !== null ? $storeValueService->getAvailableBalanceMinor($storeCreditAccount) : null;
        }

        return view('wallet::livewire.storefront.store-value-apply-widget', [
            'walletBalance' => $walletBalance,
            'storeCreditBalance' => $storeCreditBalance,
            'session' => $this->session(),
        ]);
    }
}
