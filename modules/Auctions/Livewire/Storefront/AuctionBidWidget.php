<?php

declare(strict_types=1);

namespace Modules\Auctions\Livewire\Storefront;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Exceptions\AuctionException;
use Modules\Auctions\Models\Auction;
use Modules\Auctions\Services\AuctionBiddingService;
use Modules\Auctions\Services\AuctionSettlementService;
use Modules\Customers\Services\CustomerProfileService;

class AuctionBidWidget extends Component
{
    public int $productId;

    public string $bidAmount = '';

    public ?string $errorMessage = null;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
    }

    private function auction(): ?Auction
    {
        return Auction::where('product_id', $this->productId)
            ->whereIn('status', [AuctionStatus::Active, AuctionStatus::Ended, AuctionStatus::Settled])
            ->orderByDesc('id')
            ->first();
    }

    public function placeBid(AuctionBiddingService $biddingService, CustomerProfileService $profileService): void
    {
        $this->errorMessage = null;

        if (! auth()->check()) {
            session()->flash('error', __('Please sign in to bid.'));
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $auction = $this->auction();
        if ($auction === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $profile = $profileService->firstOrCreateFor($user);

        $amountMinor = (int) round(((float) $this->bidAmount) * 100);

        try {
            $biddingService->placeBid($auction->tenant_id, $auction->id, $profile, $amountMinor);
        } catch (AuctionException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->bidAmount = '';
    }

    public function proceedToWinnerCheckout(AuctionSettlementService $settlementService, CustomerProfileService $profileService): void
    {
        $auction = $this->auction();
        if ($auction === null || $auction->status !== AuctionStatus::Ended || ! auth()->check()) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $profile = $profileService->firstOrCreateFor($user);

        if ($auction->current_bidder_customer_profile_id !== $profile->id) {
            return;
        }

        $session = $settlementService->createWinnerCheckout($auction);
        $this->redirect(route('storefront.checkout.resume', ['resumeCheckoutSessionId' => $session->id]), navigate: true);
    }

    public function render(CustomerProfileService $profileService): View
    {
        $auction = $this->auction();

        $bids = $auction !== null
            ? $auction->bids()->with('bidder.user')->orderByDesc('placed_at')->limit(10)->get()
            : collect();

        $isWinner = false;
        /** @var User|null $user */
        $user = auth()->user();
        if ($auction !== null && $user !== null && $auction->status === AuctionStatus::Ended) {
            $profile = $profileService->firstOrCreateFor($user);
            $isWinner = $auction->current_bidder_customer_profile_id === $profile->id;
        }

        return view('auctions::livewire.storefront.auction-bid-widget', [
            'auction' => $auction,
            'bids' => $bids,
            'isWinner' => $isWinner,
        ]);
    }
}
