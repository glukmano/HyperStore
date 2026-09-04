@props(['product'])

<x-ui.card class="h-full">
    <a href="{{ route('storefront.product', ['sku' => $product->sku]) }}" wire:navigate class="block">
        <h3 class="font-semibold text-base-content">{{ $product->name }}</h3>
        <p class="text-sm text-base-content/60 mt-1">{{ $product->sku }}</p>
    </a>
</x-ui.card>
