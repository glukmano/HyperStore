<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Catalog\Models\Product;
use Modules\DigitalDelivery\Models\DigitalAsset;
use RuntimeException;

class DigitalAssetManager extends Component
{
    use WithFileUploads;

    public int $productId = 0;

    public mixed $file = null;

    public string $maxDownloads = '';

    public string $downloadExpiryDays = '';

    public function mount(): void
    {
        $this->assertCan('digital-delivery.assets.view');
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

    public function upload(): void
    {
        $this->assertCan('digital-delivery.assets.manage');

        $this->validate([
            'productId' => 'required|integer|min:1',
            'file' => 'required|file|max:512000',
        ]);

        $product = Product::where('tenant_id', $this->tenantId())->findOrFail($this->productId);

        $path = $this->file->store('digital-assets/'.$this->tenantId(), 'local');
        $priorVersion = (int) DigitalAsset::where('tenant_id', $this->tenantId())->where('product_id', $product->id)->max('version');

        DigitalAsset::create([
            'tenant_id' => $this->tenantId(),
            'product_id' => $product->id,
            'disk' => 'local',
            'path' => $path,
            'version' => $priorVersion + 1,
            'checksum' => hash_file('sha256', $this->file->getRealPath()) ?: null,
            'max_downloads' => $this->maxDownloads !== '' ? (int) $this->maxDownloads : null,
            'download_expiry_days' => $this->downloadExpiryDays !== '' ? (int) $this->downloadExpiryDays : null,
        ]);

        $this->reset(['productId', 'file', 'maxDownloads', 'downloadExpiryDays']);
        session()->flash('success', 'Digital asset uploaded.');
    }

    public function render(): View
    {
        $assets = DigitalAsset::where('tenant_id', $this->tenantId())
            ->with('product')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('digital-delivery::livewire.control-center.digital-asset-manager', [
            'assets' => $assets,
        ])->layout('layouts.control-center', ['title' => 'Digital Assets']);
    }
}
