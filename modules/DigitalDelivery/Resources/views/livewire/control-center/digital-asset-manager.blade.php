<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Digital Assets</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Upload a New Version">
        <form wire:submit="upload" class="grid gap-4 sm:grid-cols-4 items-end">
            <div>
                <label class="label">Product ID</label>
                <input type="number" wire:model="productId" class="input input-bordered w-full" />
                @error('productId') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">File</label>
                <input type="file" wire:model="file" class="file-input file-input-bordered w-full" />
                @error('file') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">Max Downloads</label>
                <input type="number" wire:model="maxDownloads" class="input input-bordered w-full" placeholder="unlimited" />
            </div>
            <div>
                <label class="label">Expiry (days)</label>
                <input type="number" wire:model="downloadExpiryDays" class="input input-bordered w-full" placeholder="never" />
            </div>
            <div class="sm:col-span-4">
                <x-ui.button type="submit" variant="primary">Upload</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Existing Assets">
        <x-ui.table :headers="['Product', 'Version', 'Max Downloads', 'Expiry (days)', 'Uploaded']" :empty="$assets->isEmpty()" emptyMessage="No digital assets yet.">
            @foreach ($assets as $asset)
                <tr wire:key="da-{{ $asset->id }}">
                    <td>{{ $asset->product?->name }}</td>
                    <td>v{{ $asset->version }}</td>
                    <td>{{ $asset->max_downloads ?? 'Unlimited' }}</td>
                    <td>{{ $asset->download_expiry_days ?? 'Never' }}</td>
                    <td>{{ $asset->created_at->format('Y-m-d') }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
