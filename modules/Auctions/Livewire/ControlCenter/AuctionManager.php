<?php

declare(strict_types=1);

namespace Modules\Auctions\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Models\Auction;
use Modules\Auctions\Services\AuctionInventoryReservationService;
use RuntimeException;

class AuctionManager extends Component
{
    public int $productId = 0;

    public string $currency = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public ?int $reservePriceMinor = null;

    public int $startingPriceMinor = 0;

    public int $bidIncrementMinor = 0;

    public int $winnerPaymentWindowMinutes = 1440;

    public function mount(): void
    {
        $this->assertCan('auctions.view');
    }

    private function assertCan(string $permission): void
    {
        if (! auth()->user()?->can($permission) && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    private function tenantId(): int
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function createAuction(): void
    {
        $this->assertCan('auctions.manage');

        $tenantId = $this->tenantId();
        $storeId = app(ContextManager::class)->getStore()->getId();

        Auction::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'product_id' => $this->productId,
            'currency' => strtoupper($this->currency),
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'reserve_price_minor' => $this->reservePriceMinor,
            'starting_price_minor' => $this->startingPriceMinor,
            'bid_increment_minor' => $this->bidIncrementMinor,
            'status' => AuctionStatus::Scheduled,
            'winner_payment_window_minutes' => $this->winnerPaymentWindowMinutes,
        ]);

        $this->reset(['productId', 'currency', 'startsAt', 'endsAt', 'reservePriceMinor', 'startingPriceMinor', 'bidIncrementMinor']);
        session()->flash('success', 'Auction scheduled.');
    }

    public function cancelAuction(int $auctionId, AuctionInventoryReservationService $inventoryReservationService): void
    {
        $this->assertCan('auctions.manage');

        $auction = Auction::where('tenant_id', $this->tenantId())->findOrFail($auctionId);
        $inventoryReservationService->release($auction);
        $auction->update(['status' => AuctionStatus::Cancelled]);

        session()->flash('success', 'Auction cancelled.');
    }

    public function render(): View
    {
        $auctions = Auction::where('tenant_id', $this->tenantId())
            ->with(['product', 'currentBid.bidder.user'])
            ->orderByDesc('id')
            ->get();

        return view('auctions::livewire.control-center.auction-manager', [
            'auctions' => $auctions,
        ]);
    }
}
