<div class="max-w-4xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-base-content">{{ $productId ? 'Edit Product' : 'Create Product' }}</h1>
        <a href="{{ route('control-center.catalog.products.index') }}" class="btn btn-ghost">Cancel</a>
    </div>

    @if (session('success'))
        <x-ui.alert type="success">{{ session('success') }}</x-ui.alert>
    @endif

    <form wire:submit.prevent="save" class="space-y-6">
        <x-ui.card title="Basic Information">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-ui.input label="SKU" wire:model="sku" placeholder="e.g. TSHIRT-RED-M" required />
                <x-ui.input label="Product Name" wire:model="name" placeholder="e.g. Classic Cotton T-Shirt" required />

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Product Type</span></label>
                    <select wire:model="productType" class="select select-bordered" required>
                        @foreach ($productTypes as $type)
                            <option value="{{ $type->getId() }}">{{ $type->getName() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Brand</span></label>
                    <select wire:model="brandId" class="select select-bordered">
                        <option value="">No Brand</option>
                        @foreach ($brands as $b)
                            <option value="{{ $b->id }}">{{ $b->translation()?->name ?? $b->code }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Status</span></label>
                    <select wire:model="status" class="select select-bordered">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-3">
            <button type="submit" class="btn btn-primary">Save Product</button>
        </div>
    </form>
</div>
