<?php

declare(strict_types=1);

namespace Modules\POS\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Markets\Models\StoreMarket;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Models\InventorySource;
use Modules\POS\Models\PosRegister;
use RuntimeException;

class RegisterManager extends Component
{
    public int $storeMarketId = 0;

    public int $channelId = 0;

    public int $inventorySourceId = 0;

    public string $code = '';

    public string $name = '';

    public function mount(): void
    {
        $this->assertCan('pos.registers.view');
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

    public function createRegister(): void
    {
        $this->assertCan('pos.registers.manage');

        $this->validate([
            'storeMarketId' => 'required|integer|min:1',
            'channelId' => 'required|integer|min:1',
            'inventorySourceId' => 'required|integer|min:1',
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:150',
        ]);

        PosRegister::create([
            'tenant_id' => $this->tenantId(),
            'store_market_id' => $this->storeMarketId,
            'channel_id' => $this->channelId,
            'inventory_source_id' => $this->inventorySourceId,
            'code' => $this->code,
            'name' => $this->name,
            'status' => 'active',
        ]);

        $this->reset(['storeMarketId', 'channelId', 'inventorySourceId', 'code', 'name']);
        session()->flash('success', 'Register created.');
    }

    public function toggleStatus(int $registerId): void
    {
        $this->assertCan('pos.registers.manage');

        /** @var PosRegister $register */
        $register = PosRegister::where('tenant_id', $this->tenantId())->findOrFail($registerId);
        $register->status = $register->status === 'active' ? 'inactive' : 'active';
        $register->save();
    }

    public function render(): View
    {
        $registers = PosRegister::where('tenant_id', $this->tenantId())
            ->with(['storeMarket.store', 'storeMarket.market', 'channel', 'inventorySource'])
            ->orderByDesc('id')
            ->get();

        $storeMarkets = StoreMarket::where('is_active', true)
            ->whereHas('store', fn ($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['store', 'market'])
            ->get();

        $inventorySources = InventorySource::where('tenant_id', $this->tenantId())->get();

        return view('pos::livewire.control-center.register-manager', [
            'registers' => $registers,
            'storeMarkets' => $storeMarkets,
            'inventorySources' => $inventorySources,
        ]);
    }
}
