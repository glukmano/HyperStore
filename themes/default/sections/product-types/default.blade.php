{{-- Generic, capability-driven product detail section. Covers every Product Type whose
     storefront needs do not diverge from title/price/description/variant-selector/add-to-cart.
     Only a genuinely divergent type gets its own `sections/product-types/{key}.blade.php`. --}}
<x-ui.card>
    <div class="grid md:grid-cols-2 gap-8">
        <div class="bg-base-200 rounded-box aspect-square flex items-center justify-center text-base-content/30">
            {{ __('No image') }}
        </div>

        <div class="space-y-4">
            <h1 class="text-2xl font-bold">{{ $product->name }}</h1>
            <p class="text-sm text-base-content/60">{{ __('SKU') }}: {{ $product->sku }}</p>

            @if($price)
                <p class="text-2xl font-semibold">{{ $price->unitPrice->format() }}</p>
            @endif

            @if($product->translation()?->description)
                <p class="text-base-content/70">{{ $product->translation()->description }}</p>
            @endif

            @if($product->getTypeDefinition()->supportsVariants() && $product->variants->isNotEmpty())
                <div class="form-control w-full max-w-xs">
                    <label class="label"><span class="label-text">{{ __('Options') }}</span></label>
                    <select wire:model="variantId" class="select select-bordered w-full">
                        <option value="">{{ __('Default') }}</option>
                        @foreach($product->variants as $variant)
                            <option value="{{ $variant->id }}">{{ $variant->sku }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="flex items-end gap-3">
                <div class="form-control w-24">
                    <label class="label"><span class="label-text">{{ __('Qty') }}</span></label>
                    <input type="number" min="1" wire:model="quantity" class="input input-bordered w-full" />
                </div>
                @if(! $availability || $availability->isInStock)
                    <x-ui.button wire:click="addToCart" variant="primary">{{ __('Add to Cart') }}</x-ui.button>
                @else
                    <x-ui.button wire:click="subscribeBackInStockAlert" variant="secondary">{{ __('Notify Me When Available') }}</x-ui.button>
                @endif
            </div>

            <div class="flex flex-wrap gap-2 pt-2">
                <x-ui.button wire:click="toggleWishlist" variant="ghost" size="sm">
                    {{ $isInWishlist ? __('♥ In Wishlist') : __('♡ Add to Wishlist') }}
                </x-ui.button>
                <x-ui.button wire:click="toggleFollow" variant="ghost" size="sm">
                    {{ $isFollowing ? __('★ Following') : __('☆ Follow this Product') }}
                </x-ui.button>
                <x-ui.button wire:click="subscribePriceDropAlert" variant="ghost" size="sm">
                    {{ __('Alert me on price drop') }}
                </x-ui.button>
                <x-ui.button wire:click="toggleCompare" variant="ghost" size="sm">
                    {{ $isInCompare ? __('Remove from Compare') : __('Add to Compare') }}
                </x-ui.button>
                <a href="{{ route('storefront.compare') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('View Compare') }}</a>
            </div>
        </div>
    </div>
</x-ui.card>
