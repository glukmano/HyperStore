<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Create Promotion">
            <form wire:submit.prevent="createPromotion" class="space-y-4">
                <x-ui.input label="Name" wire:model="name" placeholder="Summer 10% Off" required />
                <x-ui.input label="Code" wire:model="code" placeholder="summer-10" required />
                <x-ui.input label="Priority" type="number" wire:model="priority" />

                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="checkbox" wire:model="is_exclusive" class="checkbox checkbox-sm checkbox-primary" />
                    <span>Exclusive (stops other discounts)</span>
                </label>

                <button type="submit" class="btn btn-primary w-full">Create Promotion</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Active Promotions">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Priority</th>
                        <th>Exclusive</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($promotions as $p)
                        <tr wire:key="promotion-{{ $p->id }}">
                            <td class="font-mono text-xs">{{ $p->code }}</td>
                            <td>{{ $p->name }}</td>
                            <td>{{ $p->priority }}</td>
                            <td>{{ $p->is_exclusive ? 'Yes' : 'No' }}</td>
                            <td><x-ui.badge variant="{{ $p->status === 'active' ? 'success' : 'ghost' }}">{{ $p->status }}</x-ui.badge></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="editPromotion({{ $p->id }})">Edit</button>
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="toggleStatus({{ $p->id }})">
                                    {{ $p->status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-base-content/50">No promotions configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$editingId !== null" title="Edit Promotion" wireClose="cancelEdit">
        <form wire:submit.prevent="updatePromotion" class="space-y-4">
            <x-ui.input label="Name" wire:model="editName" required />
            <x-ui.input label="Code" wire:model="editCode" required />
            <x-ui.input label="Priority" type="number" wire:model="editPriority" />
            <label class="flex items-center gap-2 cursor-pointer text-sm">
                <input type="checkbox" wire:model="editIsExclusive" class="checkbox checkbox-sm checkbox-primary" />
                <span>Exclusive (stops other discounts)</span>
            </label>
            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEdit">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
