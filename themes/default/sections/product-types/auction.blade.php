{{-- Phase-20 Auctions: the Auction Product Type genuinely diverges from the
     generic add-to-cart flow (no normal price/quantity/add-to-cart — the
     "price" is the live winning bid, and Add-to-Cart is blocked entirely
     while bidding is open, Owner Delta §7) — hence its own template, not a
     capability-flag branch inside default.blade.php. --}}
<x-ui.card>
    <div class="grid md:grid-cols-2 gap-8">
        <div class="bg-base-200 rounded-box aspect-square flex items-center justify-center text-base-content/30">
            {{ __('No image') }}
        </div>

        <div class="space-y-4">
            <h1 class="text-2xl font-bold">{{ $product->name }}</h1>
            <p class="text-sm text-base-content/60">{{ __('SKU') }}: {{ $product->sku }}</p>

            @if($product->translation()?->description)
                <p class="text-base-content/70">{{ $product->translation()->description }}</p>
            @endif
        </div>
    </div>

    <livewire:auctions.storefront.auction-bid-widget :product-id="$product->id" />
</x-ui.card>
