<?php

declare(strict_types=1);

namespace Modules\Promotions\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;

class CouponManager extends Component
{
    public ?int $promotionId = null;

    public string $code = '';

    public ?int $usageLimit = null;

    public ?int $editingId = null;

    public string $editCode = '';

    public ?int $editUsageLimit = null;

    public function createCoupon(): void
    {
        $this->validate([
            'promotionId' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:50'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        Coupon::create([
            'tenant_id' => $tenantId,
            'promotion_id' => $this->promotionId,
            'code' => strtoupper($this->code),
            'usage_limit' => $this->usageLimit,
            'status' => 'active',
        ]);

        $this->reset(['promotionId', 'code', 'usageLimit']);
        session()->flash('success', 'Coupon created.');
    }

    public function editCoupon(int $id): void
    {
        if (! auth()->user()?->can('coupons.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $coupon = Coupon::where('tenant_id', $tenantId)->findOrFail($id);

        $this->editingId = $coupon->id;
        $this->editCode = $coupon->code;
        $this->editUsageLimit = $coupon->usage_limit;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editCode', 'editUsageLimit']);
    }

    public function updateCoupon(): void
    {
        if (! auth()->user()?->can('coupons.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingId === null) {
            return;
        }

        $this->validate(['editCode' => ['required', 'string', 'max:50']]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $coupon = Coupon::where('tenant_id', $tenantId)->findOrFail($this->editingId);

        $coupon->update([
            'code' => strtoupper($this->editCode),
            'usage_limit' => $this->editUsageLimit,
        ]);

        $this->reset(['editingId', 'editCode', 'editUsageLimit']);
        session()->flash('success', 'Coupon updated.');
    }

    /**
     * Deactivate/reactivate via the existing `status` lifecycle field — the same
     * mechanism CouponValidationService already reads (`where('status', 'active')`).
     * No hard delete exists or is invented; this is the real existing lifecycle op.
     */
    public function toggleStatus(int $id): void
    {
        if (! auth()->user()?->can('coupons.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $coupon = Coupon::where('tenant_id', $tenantId)->findOrFail($id);

        $coupon->update(['status' => $coupon->status === 'active' ? 'inactive' : 'active']);

        session()->flash('success', $coupon->status === 'active' ? 'Coupon activated.' : 'Coupon deactivated.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('promotions::livewire.coupon-manager', [
            'promotions' => Promotion::where('tenant_id', $tenantId)->get(),
            'coupons' => Coupon::where('tenant_id', $tenantId)->with('promotion')->get(),
        ])->layout('layouts.control-center', ['title' => 'Coupons']);
    }
}
