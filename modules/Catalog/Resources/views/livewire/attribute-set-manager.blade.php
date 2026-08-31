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
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Attributes Count</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sets as $set)
                        <tr>
                            <td class="font-mono text-xs">{{ $set->code }}</td>
                            <td>{{ $set->name }}</td>
                            <td><x-ui.badge variant="ghost">{{ $set->attributes->count() }} attributes</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center py-4 text-base-content/50">No attribute sets found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
