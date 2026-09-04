@php
    $products = app(\Modules\Cms\Services\ProductGridResolver::class)->resolve($config);
@endphp

<section class="grid grid-cols-2 md:grid-cols-4 gap-4 py-4">
    @foreach ($products as $product)
        <x-ui.card>
            <div class="font-medium">{{ $product->name }}</div>
            <div class="text-sm text-base-content/60">{{ $product->sku }}</div>
        </x-ui.card>
    @endforeach
</section>
