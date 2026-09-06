<?php

declare(strict_types=1);

namespace Modules\POS\Livewire\Terminal;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\POS\DTOs\PosTenderSelection;
use Modules\POS\Enums\RegisterSessionStatus;
use Modules\POS\Models\PosRegisterSession;
use Modules\POS\Services\BarcodeLookupService;
use Modules\POS\Services\PosSaleOrchestrator;
use RuntimeException;
use Throwable;

class PosTerminal extends Component
{
    public int $sessionId = 0;

    public string $barcodeInput = '';

    public string $tenderType = 'cash';

    public string $cashTendered = '0.00';

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public function mount(int $session): void
    {
        $this->assertCan('pos.sale.create');
        $this->sessionId = $session;
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

    private function session(): PosRegisterSession
    {
        return PosRegisterSession::where('tenant_id', $this->tenantId())
            ->where('status', RegisterSessionStatus::ACTIVE->value)
            ->findOrFail($this->sessionId);
    }

    private function storeIdFor(PosRegisterSession $session): int
    {
        $register = $session->register;
        $storeMarket = $register?->storeMarket;
        if ($storeMarket === null) {
            throw new RuntimeException("RegisterSession [{$session->id}] has no resolvable Store.");
        }

        return (int) $storeMarket->store_id;
    }

    public function addByBarcode(BarcodeLookupService $lookup, PosSaleOrchestrator $orchestrator, CartServiceInterface $cartService): void
    {
        $this->errorMessage = null;

        if (trim($this->barcodeInput) === '') {
            return;
        }

        try {
            $session = $this->session();
            $cart = $orchestrator->getOrCreateCart($session, null, "pos-walkin-{$session->id}");

            $match = $lookup->resolve($this->tenantId(), $this->storeIdFor($session), $this->barcodeInput);

            $cartService->addLine($cart, new CartLineItemData(
                productId: $match->productId,
                variantId: $match->variantId,
                quantity: CartQuantity::fromInt(1),
            ));

            $this->barcodeInput = '';
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function completeSale(PosSaleOrchestrator $orchestrator, CartServiceInterface $cartService): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        try {
            $session = $this->session();
            $cart = $orchestrator->getOrCreateCart($session, null, "pos-walkin-{$session->id}");

            $tender = $this->tenderType === 'cash'
                ? new PosTenderSelection(cashAmountMinor: (int) round(((float) $this->cashTendered) * 100))
                : new PosTenderSelection(cardProviderCode: 'fake');

            $result = $orchestrator->completeSale($session, $cart, (string) Str::uuid(), $tender);

            $this->successMessage = "Sale completed — Order [{$result->order->order_number}] / Receipt [{$result->receipt->receipt_number}].";
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(): View
    {
        $session = $this->session();
        $cart = Cart::where('tenant_id', $this->tenantId())
            ->where('store_id', $this->storeIdFor($session))
            ->where('status', 'active')
            ->with('lines.product')
            ->latest('id')
            ->first();

        return view('pos::livewire.terminal.pos-terminal', [
            'session' => $session,
            'cart' => $cart,
        ]);
    }
}
