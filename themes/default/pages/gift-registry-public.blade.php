<div class="space-y-6">
    @if($registry === null)
        <x-ui.empty-state message="{{ __('This gift registry could not be found.') }}" />
    @else
        <x-ui.card>
            <h1 class="text-2xl font-bold">{{ $registry->title }}</h1>
            @if($registry->message)
                <p class="text-base-content/70 mt-2">{{ $registry->message }}</p>
            @endif
        </x-ui.card>

        <x-ui.table :headers="[__('Product'), __('Requested'), __('Remaining'), '']">
            @foreach($registry->items as $item)
                <tr wire:key="pub-ritem-{{ $item->id }}">
                    <td>{{ $item->product?->name ?? ('#'.$item->product_id) }}</td>
                    <td>{{ $item->quantity_requested }}</td>
                    <td>{{ $item->remainingQuantity() }}</td>
                    <td class="text-end">
                        @if($item->isFullyPurchased())
                            <x-ui.badge variant="success">{{ __('Fulfilled') }}</x-ui.badge>
                        @else
                            <x-ui.button variant="primary" size="sm" wire:click="buyItem({{ $item->id }})">{{ __('Buy This Gift') }}</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</div>
