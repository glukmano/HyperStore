<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">License Key Pools</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($revealedCode)
        <x-ui.alert variant="warning">Key #{{ $revealedKeyId }}: <strong>{{ $revealedCode }}</strong> (this reveal has been audit-logged)</x-ui.alert>
    @endif

    <x-ui.card title="Bulk Import Keys">
        <form wire:submit="bulkImport" class="space-y-4">
            <div>
                <label class="label">Product ID</label>
                <input type="number" wire:model="productId" class="input input-bordered w-full sm:w-64" />
                @error('productId') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">Codes (one per line)</label>
                <textarea wire:model="bulkCodes" class="textarea textarea-bordered w-full" rows="6"></textarea>
                @error('bulkCodes') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <x-ui.button type="submit" variant="primary">Import</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="Pool Status (counts only)">
        @foreach ($counts as $productId => $rows)
            <div class="mb-4">
                <h3 class="font-semibold">{{ $rows->first()->product?->name ?? "Product #{$productId}" }}</h3>
                <x-ui.table :headers="['Status', 'Count']">
                    @foreach ($rows as $row)
                        <tr wire:key="lkp-{{ $productId }}-{{ $row->status }}">
                            <td>{{ ucfirst($row->status) }}</td>
                            <td>{{ $row->total }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </div>
        @endforeach
    </x-ui.card>
</div>
