<div class="space-y-6">
    <h2 class="text-xl font-bold">Carriers</h2>
    <form wire:submit="createCarrier" class="bg-base-100 p-4 rounded-lg shadow space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <input type="text" wire:model="code" placeholder="Carrier Code (e.g. dhl, post_ch)" class="input input-bordered" required />
            <input type="text" wire:model="name" placeholder="Carrier Name" class="input input-bordered" required />
            <input type="text" wire:model="providerCode" placeholder="Provider Code (e.g. manual)" class="input input-bordered" required />
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Create Carrier</button>
    </form>
    <div class="overflow-x-auto">
        <table class="table w-full">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Provider</th>
                    <th>Services</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($carriers as $c)
                    <tr>
                        <td>{{ $c->code }}</td>
                        <td>{{ $c->name }}</td>
                        <td>{{ $c->provider_code }}</td>
                        <td>{{ $c->services->count() }}</td>
                        <td><span class="badge badge-success">{{ $c->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center">No carriers configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
