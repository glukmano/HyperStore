<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Users &amp; Roles</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Assign Roles">
        <div class="grid gap-6 sm:grid-cols-2">
            <x-ui.select wire:model.live="selectedUserId" label="User" placeholder="Select a user">
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </x-ui.select>

            @if ($selectedUserId !== null)
                <div>
                    <p class="label-text mb-2">Roles</p>
                    <div class="flex flex-col gap-1">
                        @foreach ($roles as $role)
                            <x-ui.checkbox wire:model="selectedRoles" value="{{ $role->name }}" label="{{ $role->name }}" />
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @if ($selectedUserId !== null)
            <div class="mt-4">
                <x-ui.button wire:click="saveRoles" variant="primary">Save Roles</x-ui.button>
            </div>

            <div class="mt-6">
                <p class="text-sm font-semibold mb-2">Current Effective Permissions</p>
                @if (count($currentPermissions) === 0)
                    <x-ui.empty-state message="No permissions assigned." />
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($currentPermissions as $permission)
                            <x-ui.badge variant="ghost">{{ $permission }}</x-ui.badge>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </x-ui.card>
</div>
