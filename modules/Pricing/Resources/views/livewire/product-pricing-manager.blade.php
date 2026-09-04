<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="{{ $editingPriceId ? 'Edit Product Price' : 'Set Product Price' }}">
            @if (session('success'))
                <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
            @endif

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

                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary w-full">{{ $editingPriceId ? 'Save Changes' : 'Save Price' }}</button>
                    @if ($editingPriceId)
                        <button type="button" class="btn btn-ghost" wire:click="cancelEdit">Cancel</button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Product Prices">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Product SKU</th>
                        <th>Price Book</th>
                        <th>Selling Price</th>
                        <th>Compare-At</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prices as $p)
                        <tr>
                            <td class="font-mono text-xs">{{ $p->product->sku }}</td>
                            <td>{{ $p->priceBook->name }}</td>
                            <td class="font-bold text-success">{{ $p->getMoney()->format() }}</td>
                            <td class="text-base-content/50 line-through">{{ $p->getCompareAtMoney()?->format() ?? '—' }}</td>
                            <td><x-ui.badge variant="{{ $p->status === 'active' ? 'success' : 'warning' }}">{{ $p->status }}</x-ui.badge></td>
                            <td class="flex gap-2">
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="editPrice({{ $p->id }})">Edit</button>
                                <button type="button" class="btn btn-xs btn-ghost {{ $p->status === 'active' ? 'text-error' : '' }}" wire:click="openToggleConfirm({{ $p->id }})">
                                    {{ $p->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-base-content/50">No prices configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.confirm-dialog
        :show="$confirmToggleId !== null"
        title="Change Price Status"
        message="This will toggle whether the price is used during price resolution."
        confirmAction="togglePriceStatus"
        cancelAction="cancelToggleConfirm"
        confirmLabel="Confirm"
        variant="danger"
    />
</div>
