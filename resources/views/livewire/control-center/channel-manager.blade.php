<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Channels</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Create Channel">
        <form wire:submit="createChannel" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="name" label="Name" placeholder="Channel name" error="{{ $errors->first('name') }}" />
            <x-ui.input wire:model="handle" label="Handle" placeholder="e.g. web" error="{{ $errors->first('handle') }}" />
            <x-ui.select wire:model="type" label="Type" error="{{ $errors->first('type') }}">
                <option value="website">Website</option>
                <option value="marketplace">Marketplace</option>
                <option value="pos">POS</option>
                <option value="mobile">Mobile</option>
            </x-ui.select>
            <x-ui.checkbox wire:model="is_active" label="Active" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Create Channel</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Existing Channels">
        <x-ui.table :headers="['Name', 'Handle', 'Type', 'Status']" :empty="$channels->isEmpty()" emptyMessage="No channels yet.">
            @foreach ($channels as $channel)
                <tr wire:key="channel-{{ $channel->id }}">
                    <td>{{ $channel->name }}</td>
                    <td>{{ $channel->handle }}</td>
                    <td>{{ $channel->type }}</td>
                    <td><x-ui.badge variant="{{ $channel->is_active ? 'success' : 'ghost' }}">{{ $channel->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
