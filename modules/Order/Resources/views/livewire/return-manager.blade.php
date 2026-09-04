<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-base-content">Returns / RMA</h1>
            <p class="text-sm text-base-content/60">Physical disposition of returned items</p>
        </div>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Returns' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.table :headers="['RMA #', 'SKU', 'Item', 'Approved Qty', 'Received Qty', 'Condition', 'Restock Action', 'Disposed', '']" :empty="$returnItems->isEmpty()" emptyMessage="No return items found.">
            @foreach ($returnItems as $item)
                <tr>
                    <td class="font-mono text-xs">{{ $item->sellerReturn?->seller_rma_number }}</td>
                    <td class="font-mono text-xs">{{ $item->orderItem?->sku_snapshot }}</td>
                    <td>{{ $item->orderItem?->name_snapshot }}</td>
                    <td>{{ rtrim(rtrim((string) $item->quantity_approved, '0'), '.') }}</td>
                    <td>{{ rtrim(rtrim((string) $item->quantity_received, '0'), '.') }}</td>
                    <td>{{ $item->condition ?? '—' }}</td>
                    <td><x-ui.badge variant="ghost">{{ $item->restock_action }}</x-ui.badge></td>
                    <td>
                        @if ($item->disposed_at)
                            <x-ui.badge variant="success">{{ $item->disposed_at->format('Y-m-d') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="warning">Pending</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-primary" wire:click="openDisposition({{ $item->id }})">
                            Dispose
                        </button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$returnItems" class="mt-4" />
    </x-ui.card>

    <x-ui.modal :show="$dispositionReturnItemId !== null" title="Confirm Physical Disposition" wireClose="closeDisposition">
        @if ($dispositionReturnItemId !== null)
            <div class="space-y-4">
                <x-ui.input label="Quantity Received" wire:model="quantityReceived" placeholder="e.g. 3.00000000" />

                <x-ui.input label="Condition" wire:model="condition" placeholder="e.g. unopened, damaged" />

                <x-ui.select label="Restock Action" wire:model.live="restockAction">
                    @foreach ($restockActions as $action)
                        <option value="{{ $action }}">{{ ucfirst(str_replace('_', ' ', $action)) }}</option>
                    @endforeach
                </x-ui.select>

                @if ($restockAction === 'restock')
                    <x-ui.select label="Destination Inventory Source" wire:model="destinationInventorySourceId" placeholder="Select a source">
                        @foreach ($inventorySources as $source)
                            <option value="{{ $source->id }}">{{ $source->name }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
            </div>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeDisposition">Cancel</button>
                <button type="button" class="btn btn-primary" wire:click="confirmDisposition">Confirm</button>
            </x-slot:footer>
        @endif
    </x-ui.modal>
</div>
