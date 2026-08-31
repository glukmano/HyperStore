<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Add Brand">
            <form wire:submit.prevent="createBrand" class="space-y-4">
                <x-ui.input label="Code" wire:model="code" placeholder="e.g. apple" required />
                <x-ui.input label="Name" wire:model="name" placeholder="e.g. Apple" required />
                <x-ui.input label="Slug" wire:model="slug" placeholder="e.g. apple" required />
                <button type="submit" class="btn btn-primary w-full">Create Brand</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Brands">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Slug</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($brands as $brand)
                        <tr>
                            <td class="font-mono text-xs">{{ $brand->code }}</td>
                            <td>{{ $brand->translation()?->name ?? $brand->code }}</td>
                            <td>{{ $brand->translation()?->slug ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center py-4 text-base-content/50">No brands found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
