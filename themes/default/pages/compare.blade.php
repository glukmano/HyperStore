<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ __('Compare Products') }}</h1>
        @if($products->isNotEmpty())
            <x-ui.button variant="ghost" size="sm" wire:click="clear">{{ __('Clear All') }}</x-ui.button>
        @endif
    </div>

    @if($products->isEmpty())
        <x-ui.empty-state message="{{ __('No products added to compare yet.') }}" />
    @else
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('SKU') }}</th>
                        <th>{{ __('Type') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                        <tr wire:key="compare-{{ $product->id }}">
                            <td><a href="{{ route('storefront.product', $product->sku) }}" wire:navigate class="link">{{ $product->name }}</a></td>
                            <td>{{ $product->sku }}</td>
                            <td>{{ $product->product_type }}</td>
                            <td><x-ui.button variant="ghost" size="sm" wire:click="removeProduct({{ $product->id }})">{{ __('Remove') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
