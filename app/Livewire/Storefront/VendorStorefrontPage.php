<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Services\FollowService;
use Modules\Marketplace\Contracts\VendorStorefrontResolverInterface;
use Modules\Marketplace\Models\Vendor;
use Modules\Reviews\Contracts\RatingAggregateReaderInterface;

class VendorStorefrontPage extends Component
{
    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    public function toggleFollow(FollowService $followService, VendorStorefrontResolverInterface $resolver): void
    {
        if (! auth()->check()) {
            session()->flash('error', __('Please sign in first.'));
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $vendor = $this->resolveVendor($resolver);
        if ($vendor === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();

        if ($followService->isFollowingVendor($user, $vendor->id)) {
            $followService->unfollowVendor($user, $vendor->id);
            session()->flash('success', __('Unfollowed this vendor.'));
        } else {
            $followService->followVendor($user, $vendor->id);
            session()->flash('success', __('Now following this vendor.'));
        }
    }

    public function render(VendorStorefrontResolverInterface $resolver): View
    {
        $vendor = $this->resolveVendor($resolver);
        $profile = null;

        if ($vendor !== null) {
            $storeId = app(ContextManager::class)->getStore()->getId();
            try {
                $resolved = $resolver->resolveByPath($this->slug, $storeId !== null ? (int) $storeId : null);
                $profile = $resolved->profile;
            } catch (\Throwable) {
                // vendor already resolved above; profile stays null
            }
        }

        $title = $vendor !== null ? $vendor->name : 'Vendor';

        $isFollowing = false;
        $aggregate = ['average' => 0.0, 'count' => 0];
        if ($vendor !== null) {
            if (auth()->check()) {
                /** @var User $user */
                $user = auth()->user();
                $isFollowing = app(FollowService::class)->isFollowingVendor($user, $vendor->id);
            }
            $aggregate = app(RatingAggregateReaderInterface::class)->forVendor($vendor->id);
        }

        return view('theme::pages.vendor-storefront', [
            'vendor' => $vendor,
            'profile' => $profile,
            'isFollowing' => $isFollowing,
            'aggregate' => $aggregate,
        ])->layout('theme::layouts.app', ['title' => $title]);
    }

    private function resolveVendor(VendorStorefrontResolverInterface $resolver): ?Vendor
    {
        $storeId = app(ContextManager::class)->getStore()->getId();

        try {
            return $resolver->resolveByPath($this->slug, $storeId !== null ? (int) $storeId : null)->vendor;
        } catch (\Throwable) {
            return null;
        }
    }
}
