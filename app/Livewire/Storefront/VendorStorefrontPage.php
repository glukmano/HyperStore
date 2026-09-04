<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Marketplace\Contracts\VendorStorefrontResolverInterface;

class VendorStorefrontPage extends Component
{
    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    public function render(VendorStorefrontResolverInterface $resolver): View
    {
        $storeId = app(ContextManager::class)->getStore()->getId();

        $vendor = null;
        $profile = null;

        try {
            $resolved = $resolver->resolveByPath($this->slug, $storeId !== null ? (int) $storeId : null);
            $vendor = $resolved->vendor;
            $profile = $resolved->profile;
        } catch (\Throwable) {
            $vendor = null;
        }

        $title = $vendor !== null ? $vendor->name : 'Vendor';

        return view('theme::pages.vendor-storefront', [
            'vendor' => $vendor,
            'profile' => $profile,
        ])->layout('theme::layouts.app', ['title' => $title]);
    }
}
