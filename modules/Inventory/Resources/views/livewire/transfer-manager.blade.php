<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Transfer Manager</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="xl:col-span-1">
            <x-ui.card title="Create Transfer">
                <form wire:submit.prevent="createTransfer" class="space-y-4">
                    <x-ui.input label="Transfer Number" wire:model="transfer_number" placeholder="e.g. TRF-0001" required />

                    <x-ui.select label="Source" wire:model="source_inventory_source_id" placeholder="Select source">
                        @foreach ($inventorySources as $source)
                            <option value="{{ $source->id }}">{{ $source->code }} — {{ $source->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select label="Destination" wire:model="destination_inventory_source_id" placeholder="Select destination">
                        @foreach ($inventorySources as $source)
                            <option value="{{ $source->id }}">{{ $source->code }} — {{ $source->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="space-y-3">
                        <span class="label-text">Items</span>
                        @foreach ($items as $index => $line)
                            <div class="flex items-end gap-2" wire:key="transfer-line-{{ $index }}">
                                <div class="flex-1">
                                    <x-ui.select label="Product" wire:model="items.{{ $index }}.product_id" placeholder="Select product">
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->sku }})</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                                <div class="w-28">
                                    <x-ui.input label="Qty" type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.requested_quantity" />
                                </div>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="removeItemLine({{ $index }})">✕</x-ui.button>
                            </div>
                        @endforeach
                        <x-ui.button type="button" variant="outline" size="sm" wire:click="addItemLine">+ Add Item</x-ui.button>
                    </div>

                    <x-ui.button type="submit" class="w-full">Create Transfer</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <div class="xl:col-span-2">
            <x-ui.card title="Transfers">
                <x-ui.table :headers="['Transfer #', 'Source', 'Destination', 'Items', 'Status', 'Actions']" :empty="$transfers->isEmpty()" emptyMessage="No transfers found.">
                    @foreach ($transfers as $transfer)
                        <tr wire:key="transfer-{{ $transfer->id }}">
                            <td class="font-mono text-xs">{{ $transfer->transfer_number }}</td>
                            <td>{{ $transfer->sourceInventorySource?->name ?? '—' }}</td>
                            <td>{{ $transfer->destinationInventorySource?->name ?? '—' }}</td>
                            <td>{{ $transfer->items->count() }}</td>
                            <td>
                                <x-ui.badge variant="{{ match($transfer->status) {
                                    'received' => 'success',
                                    'cancelled' => 'danger',
                                    'in_transit', 'partially_received' => 'warning',
                                    default => 'ghost',
                                } }}">
                                    {{ $transfer->status }}
                                </x-ui.badge>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    @if (in_array($transfer->status, ['draft', 'requested'], true))
                                        <x-ui.button size="sm" wire:click="dispatchTransfer({{ $transfer->id }})">Dispatch</x-ui.button>
                                        <x-ui.button size="sm" variant="danger" wire:click="confirmCancel({{ $transfer->id }})">Cancel</x-ui.button>
                                    @elseif (in_array($transfer->status, ['in_transit', 'partially_received'], true))
                                        <x-ui.button size="sm" wire:click="receiveTransfer({{ $transfer->id }})">Receive</x-ui.button>
                                    @else
                                        <span class="text-xs text-base-content/50">No actions</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$transfers" />
            </x-ui.card>
        </div>
    </div>

    <x-ui.confirm-dialog
        :show="$confirmCancelTransferId !== null"
        title="Cancel Transfer"
        message="This will cancel the transfer. This action cannot be undone."
        confirmAction="cancelTransfer"
        cancelAction="cancelCancelConfirmation"
        confirmLabel="Cancel Transfer"
        variant="danger"
    />
</div>
