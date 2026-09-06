<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">POS Terminal — {{ $session->register->name }}</h1>

        @if ($errorMessage)
            <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
        @endif
        @if ($successMessage)
            <x-ui.alert variant="success">{{ $successMessage }}</x-ui.alert>
        @endif

        <x-ui.card title="Scan / Enter Barcode or SKU">
            <form wire:submit="addByBarcode" class="flex gap-2">
                <input type="text" wire:model="barcodeInput" autofocus class="input input-bordered flex-1" placeholder="Barcode / SKU" />
                <x-ui.button type="submit" variant="primary">Add</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Cart">
            <x-ui.table :headers="['Product', 'Qty', 'Unit Price']" :empty="! $cart || $cart->lines->isEmpty()" emptyMessage="Cart is empty.">
                @if ($cart)
                    @foreach ($cart->lines as $line)
                        <tr wire:key="line-{{ $line->id }}">
                            <td>{{ $line->product->sku ?? $line->product_id }}</td>
                            <td>{{ $line->quantity }}</td>
                            <td>{{ number_format(($line->display_unit_price_minor ?? 0) / 100, 2) }}</td>
                        </tr>
                    @endforeach
                @endif
            </x-ui.table>
        </x-ui.card>
    </div>

    <div class="space-y-4">
        <x-ui.card title="Tender">
            <div class="space-y-3">
                <select wire:model="tenderType" class="select select-bordered w-full">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                </select>
                @if ($tenderType === 'cash')
                    <input type="text" wire:model="cashTendered" class="input input-bordered w-full" placeholder="Cash tendered" />
                @endif
                <x-ui.button wire:click="completeSale" variant="primary" class="w-full">Complete Sale</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
