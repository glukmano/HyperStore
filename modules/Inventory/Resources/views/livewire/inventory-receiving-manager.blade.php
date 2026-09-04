<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Inventory Receiving Manager</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1">
            <x-ui.card title="Receive Stock">
                <form wire:submit.prevent="receiveStock" class="space-y-4">
                    <x-ui.select label="Stock Item" wire:model="selectedStockItemId" placeholder="Select stock item">
                        @foreach ($stockItems as $item)
                            <option value="{{ $item->id }}">{{ $item->product?->name ?? 'Item #'.$item->id }} — {{ $item->inventorySource?->name }} (on hand: {{ $item->on_hand }})</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input label="Quantity" type="number" step="0.0001" min="0" wire:model="quantity" required />

                    <x-ui.input label="Reference" wire:model="reference" placeholder="e.g. PO-10045" />

                    <x-ui.button type="submit" class="w-full">Receive Stock</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <div class="lg:col-span-2">
            <x-ui.card title="Stock Items">
                <x-ui.table :headers="['Product', 'Source', 'On Hand', 'Incoming']" :empty="$stockItems->isEmpty()" emptyMessage="No stock items found.">
                    @foreach ($stockItems as $item)
                        <tr wire:key="recv-stock-item-{{ $item->id }}">
                            <td class="font-medium">{{ $item->product?->name ?? '—' }}</td>
                            <td>{{ $item->inventorySource?->name ?? '—' }}</td>
                            <td>{{ $item->on_hand }}</td>
                            <td>{{ $item->incoming }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>
</div>
