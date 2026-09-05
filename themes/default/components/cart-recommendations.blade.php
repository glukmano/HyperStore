<div>
    @if($suggestions->isNotEmpty())
        <div>
            <h2 class="text-xl font-bold mb-3">{{ __('You May Also Like') }}</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                @foreach($suggestions as $product)
                    <a href="{{ route('storefront.product', ['sku' => $product->sku]) }}" wire:navigate class="block">
                        <x-ui.card class="h-full">
                            <p class="text-sm font-medium truncate">{{ $product->name }}</p>
                        </x-ui.card>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
