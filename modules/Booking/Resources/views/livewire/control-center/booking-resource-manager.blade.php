<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Booking Resources</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="New Resource">
        <form wire:submit="createResource" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <x-ui.input label="Name" wire:model="name" />
            <x-ui.input type="number" label="Capacity" wire:model="capacity" />
            <x-ui.select label="Link to Service (optional)" wire:model="linkServiceId">
                <option value="">None</option>
                @foreach ($services as $service)
                    <option value="{{ $service->id }}">{{ $service->product?->sku ?? $service->id }}</option>
                @endforeach
            </x-ui.select>
            <div class="md:col-span-3">
                <x-ui.button type="submit" variant="primary">Create Resource</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Add Availability Rule">
        <form wire:submit="addAvailabilityRule" class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <x-ui.select label="Resource" wire:model="ruleResourceId">
                @foreach ($resources as $resource)
                    <option value="{{ $resource->id }}">{{ $resource->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Weekday (0=Sun)" wire:model="weekday">
                @foreach (range(0, 6) as $d)
                    <option value="{{ $d }}">{{ $d }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input type="time" label="Start" wire:model="startTime" />
            <x-ui.input type="time" label="End" wire:model="endTime" />
            <x-ui.input label="Timezone (IANA)" wire:model="timezone" />
            <div class="md:col-span-5">
                <x-ui.button type="submit" variant="primary">Add Rule</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Resources">
        <x-ui.table :headers="['Name', 'Capacity', 'Rules', 'Services']" :empty="$resources->isEmpty()" emptyMessage="No resources yet.">
            @foreach ($resources as $resource)
                <tr wire:key="resource-{{ $resource->id }}">
                    <td>{{ $resource->name }}</td>
                    <td>{{ $resource->capacity }}</td>
                    <td>{{ $resource->availabilityRules->count() }}</td>
                    <td>{{ $resource->eligibleServices->count() }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
