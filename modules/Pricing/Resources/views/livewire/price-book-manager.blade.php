<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Create Price Book">
            <form wire:submit.prevent="createPriceBook" class="space-y-4">
                <x-ui.input label="Name" wire:model="name" placeholder="e.g. Standard USD Catalog" required />
                <x-ui.input label="Code" wire:model="code" placeholder="e.g. standard-usd" required />
                <x-ui.input label="Currency" wire:model="currency" placeholder="USD, EUR, CHF" required />
                <x-ui.input label="Priority" type="number" wire:model="priority" />

                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="checkbox" wire:model="is_default" class="checkbox checkbox-sm checkbox-primary" />
                    <span>Default Base Price Book</span>
                </label>

                <button type="submit" class="btn btn-primary w-full">Create Price Book</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Price Books">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Currency</th>
                        <th>Priority</th>
                        <th>Default</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($priceBooks as $pb)
                        <tr>
                            <td class="font-mono text-xs">{{ $pb->code }}</td>
                            <td>{{ $pb->name }}</td>
                            <td><x-ui.badge variant="ghost">{{ $pb->currency }}</x-ui.badge></td>
                            <td>{{ $pb->priority }}</td>
                            <td>{{ $pb->is_default ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-4 text-base-content/50">No price books found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
