<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Inventory Source Manager</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1">
            <x-ui.card title="Create Inventory Source">
                <form wire:submit.prevent="createSource" class="space-y-4">
                    <x-ui.input label="Code" wire:model="code" placeholder="e.g. SRC-WH01" required />
                    <x-ui.input label="Name" wire:model="name" placeholder="e.g. Zurich Warehouse Source" required />

                    <x-ui.select label="Source Type" wire:model="source_type">
                        <option value="warehouse">Warehouse</option>
                        <option value="vendor">Vendor</option>
                        <option value="dropship_supplier">Dropship Supplier</option>
                        <option value="pos">Point of Sale</option>
                    </x-ui.select>

                    <x-ui.select label="Warehouse" wire:model="warehouse_id" placeholder="No warehouse">
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input label="Priority" type="number" wire:model="priority" />

                    <x-ui.button type="submit" class="w-full">Create Source</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <div class="lg:col-span-2">
            <x-ui.card title="Inventory Sources">
                <x-ui.table :headers="['Code', 'Name', 'Type', 'Warehouse', 'Priority', 'Status']" :empty="$sources->isEmpty()" emptyMessage="No inventory sources found.">
                    @foreach ($sources as $source)
                        <tr wire:key="source-{{ $source->id }}">
                            <td class="font-mono text-xs">{{ $source->code }}</td>
                            <td class="font-medium">{{ $source->name }}</td>
                            <td><x-ui.badge variant="ghost">{{ $source->source_type }}</x-ui.badge></td>
                            <td>{{ $source->warehouse?->name ?? '—' }}</td>
                            <td>{{ $source->priority }}</td>
                            <td>
                                <x-ui.badge variant="{{ $source->status === 'active' ? 'success' : 'warning' }}">
                                    {{ $source->status }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>
</div>
