<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Stores</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Create Store">
        <form wire:submit="createStore" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="name" label="Name" placeholder="Store name" error="{{ $errors->first('name') }}" />
            <x-ui.input wire:model="slug" label="Slug" placeholder="store-slug (optional)" error="{{ $errors->first('slug') }}" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Create Store</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Existing Stores">
        <x-ui.table :headers="['Name', 'Slug', 'Status', 'Active Theme', '']" :empty="$stores->isEmpty()" emptyMessage="No stores yet.">
            @foreach ($stores as $store)
                <tr wire:key="store-{{ $store->id }}">
                    <td>{{ $store->name }}</td>
                    <td>{{ $store->slug }}</td>
                    <td><x-ui.badge variant="{{ $store->status === 'active' ? 'success' : 'ghost' }}">{{ $store->status }}</x-ui.badge></td>
                    <td>
                        @if ($editingStoreId === $store->id)
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model="editingActiveTheme" class="input input-bordered input-sm" />
                                <x-ui.button wire:click="saveTheme" variant="success" size="xs">Save</x-ui.button>
                                <x-ui.button wire:click="cancelEdit" variant="ghost" size="xs">Cancel</x-ui.button>
                            </div>
                        @else
                            {{ $store->active_theme }}
                        @endif
                    </td>
                    <td class="text-end">
                        @if ($editingStoreId !== $store->id)
                            <x-ui.button wire:click="editTheme({{ $store->id }})" variant="outline" size="xs">Edit Theme</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
