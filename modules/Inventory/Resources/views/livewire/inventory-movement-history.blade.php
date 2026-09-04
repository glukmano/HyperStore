<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Inventory Movement History</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Movements">
        <x-ui.table :headers="['Date', 'Product', 'Source', 'Type', 'Delta', 'Resulting On Hand', 'Reference']" :empty="$movements->isEmpty()" emptyMessage="No inventory movements found.">
            @foreach ($movements as $movement)
                <tr wire:key="movement-{{ $movement->id }}">
                    <td class="text-xs">{{ $movement->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="font-medium">{{ $movement->product?->name ?? '—' }}</td>
                    <td>{{ $movement->inventorySource?->name ?? '—' }}</td>
                    <td><x-ui.badge variant="ghost">{{ $movement->movement_type }}</x-ui.badge></td>
                    <td class="{{ (float) $movement->quantity_delta < 0 ? 'text-error' : 'text-success' }}">{{ $movement->quantity_delta }}</td>
                    <td>{{ $movement->resulting_on_hand }}</td>
                    <td class="text-xs">{{ $movement->reference_type }}@if($movement->reference_id) #{{ $movement->reference_id }}@endif</td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$movements" />
    </x-ui.card>
</div>
