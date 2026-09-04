<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Inventory Adjustment Manager</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1">
            <x-ui.card title="Apply Adjustment">
                <form wire:submit.prevent="applyAdjustment" class="space-y-4">
                    <x-ui.select label="Stock Item" wire:model="selectedStockItemId" placeholder="Select stock item">
                        @foreach ($stockItems as $item)
                            <option value="{{ $item->id }}">{{ $item->product?->name ?? 'Item #'.$item->id }} — {{ $item->inventorySource?->name }} (on hand: {{ $item->on_hand }})</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input label="Delta" type="number" step="0.0001" wire:model="delta" placeholder="e.g. -2.0000 or 5.0000" required />

                    <x-ui.select label="Movement Type" wire:model="movementType">
                        <option value="correction">Correction</option>
                        <option value="recount">Recount</option>
                        <option value="adjustment_in">Adjustment In</option>
                        <option value="adjustment_out">Adjustment Out</option>
                        <option value="damaged">Damaged</option>
                        <option value="quarantine_in">Quarantine In</option>
                        <option value="quarantine_out">Quarantine Out</option>
                    </x-ui.select>

                    <x-ui.textarea label="Reason" wire:model="reason" placeholder="Explain the reason for this adjustment" required />

                    <x-ui.button type="submit" class="w-full">Apply Adjustment</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <div class="lg:col-span-2">
            <x-ui.card title="Stock Items">
                <x-ui.table :headers="['Product', 'Source', 'On Hand', 'Reserved', 'Quarantined', 'Damaged']" :empty="$stockItems->isEmpty()" emptyMessage="No stock items found.">
                    @foreach ($stockItems as $item)
                        <tr wire:key="adj-stock-item-{{ $item->id }}">
                            <td class="font-medium">{{ $item->product?->name ?? '—' }}</td>
                            <td>{{ $item->inventorySource?->name ?? '—' }}</td>
                            <td>{{ $item->on_hand }}</td>
                            <td>{{ $item->reserved }}</td>
                            <td>{{ $item->quarantined }}</td>
                            <td>{{ $item->damaged }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>
</div>
