<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Catalog\Models\Product;
use Modules\DigitalDelivery\Models\LicenseKeyPool;
use RuntimeException;

class LicenseKeyPoolManager extends Component
{
    public int $productId = 0;

    public string $bulkCodes = '';

    public ?int $revealedKeyId = null;

    public ?string $revealedCode = null;

    public function mount(): void
    {
        $this->assertCan('digital-delivery.licenses.view');
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

    public function bulkImport(): void
    {
        $this->assertCan('digital-delivery.licenses.manage');

        $this->validate([
            'productId' => 'required|integer|min:1',
            'bulkCodes' => 'required|string',
        ]);

        $product = Product::where('tenant_id', $this->tenantId())->findOrFail($this->productId);

        $lines = array_filter(array_map('trim', explode("\n", $this->bulkCodes)));
        foreach ($lines as $code) {
            LicenseKeyPool::create([
                'tenant_id' => $this->tenantId(),
                'product_id' => $product->id,
                'code_encrypted' => $code,
                'status' => 'available',
            ]);
        }

        $this->reset(['productId', 'bulkCodes']);
        session()->flash('success', count($lines).' license key(s) imported.');
    }

    /**
     * A single explicit reveal-one-key action, audit-logged — never a
     * decrypted list view (Owner Delta D.52). Never written to
     * Log::/report() itself; only the fact of the reveal is logged.
     */
    public function revealKey(int $keyId): void
    {
        $this->assertCan('digital-delivery.licenses.manage');

        /** @var LicenseKeyPool $key */
        $key = LicenseKeyPool::where('tenant_id', $this->tenantId())->findOrFail($keyId);

        Log::info('License key revealed by Control Center operator', [
            'tenant_id' => $this->tenantId(),
            'license_key_pool_id' => $key->id,
            'user_id' => auth()->id(),
        ]);

        $this->revealedKeyId = $keyId;
        $this->revealedCode = $key->code_encrypted;
    }

    public function render(): View
    {
        $counts = LicenseKeyPool::where('tenant_id', $this->tenantId())
            ->selectRaw('product_id, status, COUNT(*) as total')
            ->groupBy('product_id', 'status')
            ->with('product')
            ->get()
            ->groupBy('product_id');

        return view('digital-delivery::livewire.control-center.license-key-pool-manager', [
            'counts' => $counts,
        ]);
    }
}
