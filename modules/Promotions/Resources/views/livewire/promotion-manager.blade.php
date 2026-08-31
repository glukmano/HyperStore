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
                    </tr>
                </thead>
                <tbody>
                    @forelse ($promotions as $p)
                        <tr>
                            <td class="font-mono text-xs">{{ $p->code }}</td>
                            <td>{{ $p->name }}</td>
                            <td>{{ $p->priority }}</td>
                            <td>{{ $p->is_exclusive ? 'Yes' : 'No' }}</td>
                            <td><x-ui.badge variant="success">{{ $p->status }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-4 text-base-content/50">No promotions configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
