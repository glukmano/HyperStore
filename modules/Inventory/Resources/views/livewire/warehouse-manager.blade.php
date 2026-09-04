<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Warehouse Manager</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1">
            <x-ui.card title="Create Warehouse">
                <form wire:submit.prevent="createWarehouse" class="space-y-4">
                    <x-ui.input label="Code" wire:model="code" placeholder="e.g. WH-CH-01" required />
                    <x-ui.input label="Name" wire:model="name" placeholder="e.g. Zurich Fulfillment Center" required />
                    <x-ui.input label="Country Code" wire:model="country_code" placeholder="CH" maxlength="2" required />

                    <x-ui.select label="Type" wire:model="type" placeholder="Select a type">
                        <option value="fulfillment_center">Fulfillment Center</option>
                        <option value="retail_store">Retail Store</option>
                        <option value="distribution_center">Distribution Center</option>
                        <option value="hub">Hub</option>
                    </x-ui.select>

                    <x-ui.select label="Ownership Type" wire:model="ownership_type">
                        <option value="platform">Platform</option>
                        <option value="vendor">Vendor</option>
                        <option value="3pl">3PL</option>
                    </x-ui.select>

                    <x-ui.input label="Timezone" wire:model="timezone" placeholder="UTC" required />

                    <x-ui.button type="submit" class="w-full">Create Warehouse</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <div class="lg:col-span-2">
            <x-ui.card title="Warehouses">
                <x-ui.table :headers="['Code', 'Name', 'Country', 'Type', 'Ownership', 'Timezone', 'Status']" :empty="$warehouses->isEmpty()" emptyMessage="No warehouses found.">
                    @foreach ($warehouses as $warehouse)
                        <tr wire:key="warehouse-{{ $warehouse->id }}">
                            <td class="font-mono text-xs">{{ $warehouse->code }}</td>
                            <td class="font-medium">{{ $warehouse->name }}</td>
                            <td>{{ $warehouse->country_code }}</td>
                            <td>{{ $warehouse->type ?? '—' }}</td>
                            <td><x-ui.badge variant="ghost">{{ $warehouse->ownership_type }}</x-ui.badge></td>
                            <td>{{ $warehouse->timezone }}</td>
                            <td>
                                <x-ui.badge variant="{{ $warehouse->status === 'active' ? 'success' : 'warning' }}">
                                    {{ $warehouse->status }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>
</div>
