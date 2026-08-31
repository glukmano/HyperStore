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

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('promotions::livewire.coupon-manager', [
            'promotions' => Promotion::where('tenant_id', $tenantId)->get(),
            'coupons' => Coupon::where('tenant_id', $tenantId)->with('promotion')->get(),
        ])->layout('layouts.control-center', ['title' => 'Coupons']);
    }
}
