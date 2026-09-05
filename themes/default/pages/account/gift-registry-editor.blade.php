<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-6">
        <h1 class="text-2xl font-bold">{{ $registry->title }}</h1>

        <x-ui.alert variant="info">
            {{ __('Share link') }}: {{ route('registry.public', $registry->share_token) }}
            @if($registry->visibility !== 'public')
                <x-ui.button variant="ghost" size="sm" wire:click="makePublic">{{ __('Make Public') }}</x-ui.button>
            @endif
        </x-ui.alert>

        <x-ui.table :headers="[__('Product'), __('Requested'), __('Purchased'), '']">
            @foreach($registry->items as $item)
                <tr wire:key="ritem-{{ $item->id }}">
                    <td>{{ $item->product?->name ?? ('#'.$item->product_id) }}</td>
                    <td>{{ $item->quantity_requested }}</td>
                    <td>{{ $item->quantity_purchased }}</td>
                    <td>
                        @if($item->isFullyPurchased())
                            <x-ui.badge variant="success">{{ __('Fulfilled') }}</x-ui.badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.card>
            <h2 class="font-semibold mb-3">{{ __('Add an item by SKU') }}</h2>
            <form wire:submit="addItem" class="flex items-end gap-2 flex-wrap">
                <x-ui.input wire:model="sku" label="{{ __('SKU') }}" />
                <x-ui.input type="number" min="1" wire:model="quantityRequested" label="{{ __('Quantity') }}" />
                <x-ui.button type="submit" variant="primary">{{ __('Add') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
