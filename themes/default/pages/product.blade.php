<div class="space-y-6">
    @if($product === null)
        <x-ui.empty-state message="{{ __('This product could not be found.') }}" />
    @else
        <x-ui.breadcrumbs :items="['Home' => route('storefront.home'), $product->name => null]" />

        @include($sectionView, [
            'product' => $product,
            'price' => $price,
            'isInWishlist' => $isInWishlist,
            'isFollowing' => $isFollowing,
            'availability' => $availability,
            'isInCompare' => $isInCompare,
        ])

        <livewire:storefront.product-reviews-section :product-id="$product->id" />
        <livewire:storefront.product-qa-section :product-id="$product->id" />
    @endif
</div>
