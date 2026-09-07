@props(['product'])

@php
    $thumbnailUrl = $product->getFirstMediaUrl('product_thumbnail') ?: null;
@endphp

<div {{ $attributes->merge(['class' => 'card bg-base-100 shadow-sm h-full']) }}>
    <a href="{{ route('storefront.product', ['sku' => $product->sku]) }}" wire:navigate class="block">
        <figure class="aspect-square bg-base-200 flex items-center justify-center overflow-hidden">
            @if ($thumbnailUrl)
                <img src="{{ $thumbnailUrl }}" alt="{{ $product->name }}" class="w-full h-full object-cover" loading="lazy" />
            @else
                <x-icon name="image" class="w-10 h-10 text-base-content/30" />
            @endif
        </figure>
        <div class="card-body p-4">
            <h3 class="font-semibold text-base-content">{{ $product->name }}</h3>
            <p class="text-sm text-base-content/60 mt-1">{{ $product->sku }}</p>
        </div>
    </a>
</div>
