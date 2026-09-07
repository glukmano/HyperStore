<?php

declare(strict_types=1);

namespace Modules\GiftCards\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\GiftCards\Models\GiftCard;
use Modules\GiftCards\Services\GiftCardService;
use RuntimeException;

class GiftCardManager extends Component
{
    public string $issueCurrency = 'USD';

    public string $issueValue = '';

    public ?string $lastIssuedCode = null;

    public function mount(): void
    {
        $this->assertCan('gift-cards.view');
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

    public function issue(GiftCardService $giftCardService): void
    {
        $this->assertCan('gift-cards.manage');

        $this->validate([
            'issueCurrency' => 'required|string|size:3',
            'issueValue' => 'required|numeric|min:0.01',
        ]);

        $valueMinor = (int) round(((float) $this->issueValue) * 100);
        $result = $giftCardService->issue($this->tenantId(), $this->issueCurrency, $valueMinor);

        $this->lastIssuedCode = $result['plaintextCode'];
        $this->reset(['issueValue']);
        session()->flash('success', 'Gift Card issued. Deliver the code to the recipient now — it will never be shown again.');
    }

    public function deactivate(int $giftCardId): void
    {
        $this->assertCan('gift-cards.manage');

        $card = GiftCard::where('tenant_id', $this->tenantId())->findOrFail($giftCardId);
        $card->update(['status' => 'deactivated']);
        session()->flash('success', 'Gift Card deactivated.');
    }

    public function render(): View
    {
        $cards = GiftCard::where('tenant_id', $this->tenantId())
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('gift-cards::livewire.control-center.gift-card-manager', [
            'cards' => $cards,
        ])->layout('layouts.control-center', ['title' => 'Gift Cards']);
    }
}
