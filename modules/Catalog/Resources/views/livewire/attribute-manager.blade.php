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
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attributes as $attr)
                        <tr>
                            <td class="font-mono text-xs">{{ $attr->code }}</td>
                            <td>{{ $attr->translation()?->name ?? $attr->code }}</td>
                            <td><x-ui.badge variant="ghost">{{ $attr->type }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center py-4 text-base-content/50">No attributes found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
