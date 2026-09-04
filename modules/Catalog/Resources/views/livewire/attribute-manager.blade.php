<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Add Attribute">
            <form wire:submit.prevent="createAttribute" class="space-y-4">
                <x-ui.input label="Code" wire:model="code" placeholder="e.g. color" required />
                <x-ui.input label="Name" wire:model="name" placeholder="e.g. Color" required />
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Type</span></label>
                    <select wire:model="type" class="select select-bordered">
                        <option value="text">Text</option>
                        <option value="select">Select</option>
                        <option value="multiselect">Multi-Select</option>
                        <option value="integer">Integer</option>
                        <option value="decimal">Decimal</option>
                        <option value="boolean">Boolean</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-full">Create Attribute</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Attributes">
            @if (session('success'))
                <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
            @endif

            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attributes as $attr)
                        <tr>
                            <td class="font-mono text-xs">{{ $attr->code }}</td>
                            <td>{{ $attr->translation()?->name ?? $attr->code }}</td>
                            <td><x-ui.badge variant="ghost">{{ $attr->type }}</x-ui.badge></td>
                            <td class="flex gap-2">
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="editAttribute({{ $attr->id }})">Edit</button>
                                <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="openArchiveConfirm({{ $attr->id }})">Archive</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-4 text-base-content/50">No attributes found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$editingId !== null" title="Edit Attribute" wireClose="cancelEdit">
        <form wire:submit.prevent="updateAttribute" class="space-y-4">
            <x-ui.input label="Code" wire:model="editCode" required />
            <x-ui.input label="Name" wire:model="editName" required />
            <div class="form-control">
                <label class="label"><span class="label-text font-medium">Type</span></label>
                <select wire:model="editType" class="select select-bordered">
                    <option value="text">Text</option>
                    <option value="select">Select</option>
                    <option value="multiselect">Multi-Select</option>
                    <option value="integer">Integer</option>
                    <option value="decimal">Decimal</option>
                    <option value="boolean">Boolean</option>
                </select>
            </div>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEdit">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.confirm-dialog
        :show="$confirmArchiveId !== null"
        title="Archive Attribute"
        message="This will archive the attribute. It will no longer be available for new products."
        confirmAction="archiveAttribute"
        cancelAction="cancelArchiveConfirm"
        confirmLabel="Archive"
        variant="danger"
    />
</div>
