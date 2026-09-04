<div class="space-y-10">
    <section class="hero bg-base-100 rounded-box py-16">
        <div class="hero-content text-center">
            <div class="max-w-md">
                <h1 class="text-4xl font-bold">{{ __('Welcome') }}</h1>
                <p class="py-4 text-base-content/70">{{ __('Discover our latest products.') }}</p>
            </div>
        </div>
    </section>

    @if($categories->isNotEmpty())
        <section>
            <h2 class="text-xl font-bold mb-4">{{ __('Shop by Category') }}</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach($categories as $category)
                    <a href="{{ route('storefront.category', ['code' => $category->code]) }}" wire:navigate>
                        <x-ui.card compact>
                            <span class="font-medium">{{ $category->translation()?->name ?? $category->code }}</span>
                        </x-ui.card>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if($products->isNotEmpty())
        <section>
            <h2 class="text-xl font-bold mb-4">{{ __('New Arrivals') }}</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach($products as $product)
                    <x-theme::product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @else
        <x-ui.empty-state message="{{ __('No products available yet.') }}" />
    @endif
</div>
