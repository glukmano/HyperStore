<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Set Product Price">
            <form wire:submit.prevent="savePrice" class="space-y-4">
                <div class="form-control">
                    <label class="label"><span class="label-text">Select Product</span></label>
                    <select wire:model="selectedProductId" class="select select-bordered w-full" required>
                        <option value="">-- Choose Product --</option>
                        @foreach ($products as $prod)
                            <option value="{{ $prod->id }}">{{ $prod->sku }} - {{ $prod->translation()?->name ?? $prod->sku }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text">Select Price Book</span></label>
                    <select wire:model="selectedPriceBookId" class="select select-bordered w-full" required>
                        <option value="">-- Choose Price Book --</option>
                        @foreach ($priceBooks as $pb)
                            <option value="{{ $pb->id }}">{{ $pb->name }} ({{ $pb->currency }})</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.input label="Selling Price" type="number" step="0.01" wire:model="amount" placeholder="0.00" required />
                <x-ui.input label="Compare-At Price" type="number" step="0.01" wire:model="compareAt" placeholder="0.00" />
                <x-ui.input label="Cost Price (Internal)" type="number" step="0.01" wire:model="cost" placeholder="0.00" />

                <button type="submit" class="btn btn-primary w-full">Save Price</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Active Product Prices">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Product SKU</th>
                        <th>Price Book</th>
                        <th>Selling Price</th>
                        <th>Compare-At</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prices as $p)
                        <tr>
                            <td class="font-mono text-xs">{{ $p->product->sku }}</td>
                            <td>{{ $p->priceBook->name }}</td>
                            <td class="font-bold text-success">{{ $p->getMoney()->format() }}</td>
                            <td class="text-base-content/50 line-through">{{ $p->getCompareAtMoney()?->format() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-4 text-base-content/50">No prices configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
