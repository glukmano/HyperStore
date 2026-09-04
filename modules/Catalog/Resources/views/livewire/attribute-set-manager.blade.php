<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Add Attribute Set">
            <form wire:submit.prevent="createSet" class="space-y-4">
                <x-ui.input label="Name" wire:model="name" placeholder="e.g. Clothing Set" required />
                <x-ui.input label="Code" wire:model="code" placeholder="e.g. clothing" required />

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Include Attributes</span></label>
                    <div class="space-y-2 max-h-48 overflow-y-auto p-2 bg-base-200 rounded">
                        @foreach ($allAttributes as $attr)
                            <label class="flex items-center gap-2 cursor-pointer text-sm">
                                <input type="checkbox" wire:model="selectedAttributes" value="{{ $attr->id }}" class="checkbox checkbox-sm checkbox-primary" />
                                <span>{{ $attr->translation()?->name ?? $attr->code }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full">Create Attribute Set</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Attribute Sets">
            @if (session('success'))
                <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
            @endif

            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Attributes Count</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sets as $set)
                        <tr>
                            <td class="font-mono text-xs">{{ $set->code }}</td>
                            <td>{{ $set->name }}</td>
                            <td><x-ui.badge variant="ghost">{{ $set->attributes->count() }} attributes</x-ui.badge></td>
                            <td class="flex gap-2">
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="editSet({{ $set->id }})">Edit</button>
                                <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="openArchiveConfirm({{ $set->id }})">Archive</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-4 text-base-content/50">No attribute sets found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$editingId !== null" title="Edit Attribute Set" wireClose="cancelEdit">
        <form wire:submit.prevent="updateSet" class="space-y-4">
            <x-ui.input label="Name" wire:model="editName" required />
            <x-ui.input label="Code" wire:model="editCode" required />

            <div class="form-control">
                <label class="label"><span class="label-text font-medium">Include Attributes</span></label>
                <div class="space-y-2 max-h-48 overflow-y-auto p-2 bg-base-200 rounded">
                    @foreach ($allAttributes as $attr)
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" wire:model="editSelectedAttributes" value="{{ $attr->id }}" class="checkbox checkbox-sm checkbox-primary" />
                            <span>{{ $attr->translation()?->name ?? $attr->code }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEdit">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.confirm-dialog
        :show="$confirmArchiveId !== null"
        title="Archive Attribute Set"
        message="This will archive the attribute set."
        confirmAction="archiveSet"
        cancelAction="cancelArchiveConfirm"
        confirmLabel="Archive"
        variant="danger"
    />
</div>
