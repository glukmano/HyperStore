<div class="space-y-6">
    <div class="flex justify-between items-center">
        <h2 class="text-xl font-bold">Shipping Zones</h2>
    </div>
    <form wire:submit="createZone" class="bg-base-100 p-4 rounded-lg shadow space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <input type="text" wire:model="code" placeholder="Zone Code (e.g. EU_WEST)" class="input input-bordered w-full" required />
            <input type="text" wire:model="name" placeholder="Zone Name" class="input input-bordered w-full" required />
            <input type="number" wire:model="priority" placeholder="Priority" class="input input-bordered w-full" />
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Create Zone</button>
    </form>
    <div class="overflow-x-auto">
        <table class="table w-full">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Priority</th>
                    <th>Rules Count</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($zones as $zone)
                    <tr>
                        <td>{{ $zone->code }}</td>
                        <td>{{ $zone->name }}</td>
                        <td>{{ $zone->priority }}</td>
                        <td>{{ $zone->rules->count() }}</td>
                        <td><span class="badge badge-success">{{ $zone->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center">No shipping zones found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
