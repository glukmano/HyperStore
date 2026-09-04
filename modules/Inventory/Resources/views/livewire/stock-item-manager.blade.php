<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Stock Item Manager</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Stock Items">
        <x-ui.table :headers="['Product', 'Variant', 'Source', 'On Hand', 'Reserved', 'Quarantined', 'Damaged', 'Available']" :empty="$stockItems->isEmpty()" emptyMessage="No stock items found.">
            @foreach ($stockItems as $item)
                <tr wire:key="stock-item-{{ $item->id }}">
                    <td class="font-medium">{{ $item->product?->name ?? '—' }}</td>
                    <td class="font-mono text-xs">{{ $item->productVariant?->sku ?? '—' }}</td>
                    <td>{{ $item->inventorySource?->name ?? '—' }}</td>
                    <td>{{ $item->on_hand }}</td>
                    <td>{{ $item->reserved }}</td>
                    <td>{{ $item->quarantined }}</td>
                    <td>{{ $item->damaged }}</td>
                    <td>{{ $item->getAvailableToSellQuantity()->toString() }}</td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$stockItems" />
    </x-ui.card>
</div>
