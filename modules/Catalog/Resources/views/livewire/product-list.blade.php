<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-base-content">Products</h1>
            <p class="text-sm text-base-content/60">Canonical product catalog and variants</p>
        </div>
        <a href="{{ route('control-center.catalog.products.create') }}" class="btn btn-primary">
            Create Product
        </a>
    </div>

    @if (session('success'))
        <x-ui.alert type="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="flex flex-col md:flex-row gap-4 mb-4">
            <input type="text" wire:model.live="search" placeholder="Search SKU or Name..." class="input input-bordered w-full md:w-64" />
            <select wire:model.live="selectedType" class="select select-bordered">
                <option value="">All Types</option>
                <option value="physical">Physical</option>
                <option value="digital">Digital</option>
                <option value="license">License</option>
                <option value="subscription">Subscription</option>
                <option value="bundle">Bundle</option>
            </select>
            <select wire:model.live="selectedStatus" class="select select-bordered">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="draft">Draft</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Brand</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $prod)
                        <tr>
                            <td class="font-mono text-xs">{{ $prod->sku }}</td>
                            <td class="font-medium">{{ $prod->translation()?->name ?? '—' }}</td>
                            <td><x-ui.badge variant="ghost">{{ $prod->product_type }}</x-ui.badge></td>
                            <td>{{ $prod->brand?->translation()?->name ?? '—' }}</td>
                            <td><x-ui.badge variant="{{ $prod->status === 'active' ? 'success' : 'warning' }}">{{ $prod->status }}</x-ui.badge></td>
                            <td class="flex gap-2">
                                <a href="{{ route('control-center.catalog.products.edit', $prod->id) }}" class="btn btn-sm btn-ghost">Edit</a>
                                @if ($prod->status !== 'archived')
                                    <button type="button" class="btn btn-sm btn-ghost text-error" wire:click="openArchiveConfirm({{ $prod->id }})">Archive</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-6 text-base-content/50">No products found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $products->links() }}
        </div>
    </x-ui.card>

    <x-ui.confirm-dialog
        :show="$confirmArchiveId !== null"
        title="Archive Product"
        message="This will archive the product and hide it from all active store listings. This can be reversed by editing the product later."
        confirmAction="archiveProduct"
        cancelAction="cancelArchiveConfirm"
        confirmLabel="Archive"
        variant="danger"
    />
</div>
