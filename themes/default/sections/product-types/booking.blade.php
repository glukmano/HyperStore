{{-- Phase-20 Booking: diverges from the generic add-to-cart flow with a
     slot picker instead of a plain quantity/add-to-cart control. --}}
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
        </div>
    </div>

    <livewire:booking.storefront.booking-widget :product-id="$product->id" />
</x-ui.card>
