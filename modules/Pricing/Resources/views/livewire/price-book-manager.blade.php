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
            @if (session('success'))
                <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
            @endif

            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Currency</th>
                        <th>Priority</th>
                        <th>Default</th>
                        <th>Status</th>
                        <th>Actions</th>
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
                            <td><x-ui.badge variant="{{ $pb->status === 'archived' ? 'warning' : 'success' }}">{{ $pb->status }}</x-ui.badge></td>
                            <td class="flex gap-2">
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="editPriceBook({{ $pb->id }})">Edit</button>
                                <button type="button" class="btn btn-xs btn-ghost {{ $pb->status === 'archived' ? '' : 'text-error' }}" wire:click="openArchiveConfirm({{ $pb->id }})">
                                    {{ $pb->status === 'archived' ? 'Reactivate' : 'Archive' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center py-4 text-base-content/50">No price books found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$editingId !== null" title="Edit Price Book" wireClose="cancelEdit">
        <form wire:submit.prevent="updatePriceBook" class="space-y-4">
            <x-ui.input label="Name" wire:model="editName" required />
            <x-ui.input label="Code" wire:model="editCode" required />
            <x-ui.input label="Currency" wire:model="editCurrency" required />
            <x-ui.input label="Priority" type="number" wire:model="editPriority" />

            <label class="flex items-center gap-2 cursor-pointer text-sm">
                <input type="checkbox" wire:model="editIsDefault" class="checkbox checkbox-sm checkbox-primary" />
                <span>Default Base Price Book</span>
            </label>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEdit">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.confirm-dialog
        :show="$confirmArchiveId !== null"
        title="Change Price Book Status"
        message="This will toggle the price book between active and archived. Archived price books are excluded from price resolution."
        confirmAction="archivePriceBook"
        cancelAction="cancelArchiveConfirm"
        confirmLabel="Confirm"
        variant="danger"
    />
</div>
