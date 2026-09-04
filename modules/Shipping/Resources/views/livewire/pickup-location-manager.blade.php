<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Pickup Locations</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="In-Store &amp; Warehouse Pickup Points">
        <x-ui.table :headers="['Code', 'Name', 'Inventory Source', 'Warehouse', 'Fee', 'Status']" :empty="$locations->isEmpty()" emptyMessage="No pickup locations found.">
            @foreach ($locations as $location)
                <tr wire:key="pickup-location-{{ $location->id }}">
                    <td class="font-medium">{{ $location->code }}</td>
                    <td>{{ $location->name }}</td>
                    <td>{{ $location->inventorySource?->name ?? '—' }}</td>
                    <td>{{ $location->warehouse?->name ?? '—' }}</td>
                    <td>{{ $location->fee_amount }} {{ $location->currency }}</td>
                    <td><x-ui.badge :variant="$location->status === 'active' ? 'success' : 'ghost'">{{ $location->status }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
