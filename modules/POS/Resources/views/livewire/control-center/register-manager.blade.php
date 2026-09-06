<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">POS Registers</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="New Register">
        <form wire:submit="createRegister" class="grid gap-4 sm:grid-cols-5 items-end">
            <div>
                <label class="label">Store / Market</label>
                <select wire:model="storeMarketId" class="select select-bordered w-full">
                    <option value="0">Select...</option>
                    @foreach ($storeMarkets as $sm)
                        <option value="{{ $sm->id }}">{{ $sm->store->name }} / {{ $sm->market->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Channel ID</label>
                <input type="number" wire:model="channelId" class="input input-bordered w-full" />
            </div>
            <div>
                <label class="label">Inventory Source</label>
                <select wire:model="inventorySourceId" class="select select-bordered w-full">
                    <option value="0">Select...</option>
                    @foreach ($inventorySources as $src)
                        <option value="{{ $src->id }}">{{ $src->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Code</label>
                <input type="text" wire:model="code" class="input input-bordered w-full" />
            </div>
            <div>
                <label class="label">Name</label>
                <input type="text" wire:model="name" class="input input-bordered w-full" />
            </div>
            <div class="sm:col-span-5">
                <x-ui.button type="submit" variant="primary">Create Register</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Registers">
        <x-ui.table :headers="['Code', 'Name', 'Store', 'Market', 'Inventory Source', 'Status', '']" :empty="$registers->isEmpty()" emptyMessage="No registers yet.">
            @foreach ($registers as $register)
                <tr wire:key="reg-{{ $register->id }}">
                    <td>{{ $register->code }}</td>
                    <td>{{ $register->name }}</td>
                    <td>{{ $register->storeMarket->store->name }}</td>
                    <td>{{ $register->storeMarket->market->name }}</td>
                    <td>{{ $register->inventorySource->name }}</td>
                    <td>{{ ucfirst($register->status) }}</td>
                    <td>
                        <x-ui.button size="sm" wire:click="toggleStatus({{ $register->id }})">Toggle</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
