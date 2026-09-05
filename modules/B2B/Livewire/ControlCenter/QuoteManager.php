<?php

declare(strict_types=1);

namespace Modules\B2B\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\B2B\Enums\QuoteStatus;
use Modules\B2B\Models\Quote;
use Modules\B2B\Services\QuoteService;
use RuntimeException;

class QuoteManager extends Component
{
    public ?int $editingQuoteId = null;

    /** @var array<int, int> */
    public array $lineePrices = [];

    public function mount(): void
    {
        $this->assertCan('b2b.quotes.view');
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

    public function editQuote(int $quoteId): void
    {
        $this->assertCan('b2b.quotes.manage');

        $quote = Quote::where('tenant_id', $this->tenantId())->with('lines')->findOrFail($quoteId);
        $this->editingQuoteId = $quote->id;
        $this->lineePrices = $quote->lines->mapWithKeys(fn ($l) => [$l->id => (int) ($l->negotiated_unit_price_minor ?? 0)])->all();
    }

    public function savePrices(QuoteService $quoteService): void
    {
        $this->assertCan('b2b.quotes.manage');

        $quote = Quote::where('tenant_id', $this->tenantId())->findOrFail($this->editingQuoteId);

        /** @var User $staff */
        $staff = auth()->user();

        $quoteService->quotePrices($quote, $staff, $this->lineePrices);

        $this->reset(['editingQuoteId', 'lineePrices']);
        session()->flash('success', 'Quote priced and sent to customer.');
    }

    public function reject(int $quoteId, QuoteService $quoteService): void
    {
        $this->assertCan('b2b.quotes.manage');

        $quote = Quote::where('tenant_id', $this->tenantId())->findOrFail($quoteId);
        $quoteService->reject($quote);
        session()->flash('success', 'Quote rejected.');
    }

    public function render(): View
    {
        $quotes = Quote::where('tenant_id', $this->tenantId())
            ->whereIn('status', [QuoteStatus::Submitted, QuoteStatus::Quoted, QuoteStatus::Accepted, QuoteStatus::Rejected, QuoteStatus::Expired])
            ->with(['lines', 'company'])
            ->orderByDesc('id')
            ->get();

        return view('b2b::livewire.control-center.quote-manager', [
            'quotes' => $quotes,
        ]);
    }
}
