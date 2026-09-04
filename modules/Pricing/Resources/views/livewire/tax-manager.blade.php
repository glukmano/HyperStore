<div class="space-y-6">
    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <x-ui.card title="Tax Classes">
            <form wire:submit.prevent="createClass" class="flex gap-2 mb-4">
                <input type="text" wire:model="className" placeholder="Name (e.g. Standard)" class="input input-bordered input-sm w-full" required />
                <input type="text" wire:model="classCode" placeholder="Code (standard)" class="input input-bordered input-sm w-full" required />
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
            <ul class="divide-y divide-base-300">
                @foreach ($classes as $c)
                    <li class="py-2 flex justify-between items-center">
                        <span>{{ $c->name }}</span>
                        <span class="flex items-center gap-2">
                            <code class="text-xs">{{ $c->code }}</code>
                            <button type="button" class="btn btn-xs btn-ghost" wire:click="editClass({{ $c->id }})">Edit</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card title="Tax Zones">
            <form wire:submit.prevent="createZone" class="flex gap-2 mb-4">
                <input type="text" wire:model="zoneName" placeholder="Zone Name" class="input input-bordered input-sm w-full" required />
                <input type="text" wire:model="zoneCode" placeholder="Zone Code" class="input input-bordered input-sm w-full" required />
                <input type="text" wire:model="countryCode" placeholder="CC (US)" class="input input-bordered input-sm w-20" required />
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
            <ul class="divide-y divide-base-300">
                @foreach ($zones as $z)
                    <li class="py-2 flex justify-between items-center">
                        <span>{{ $z->name }} ({{ $z->country_code }})</span>
                        <span class="flex items-center gap-2">
                            <code class="text-xs">{{ $z->code }}</code>
                            <button type="button" class="btn btn-xs btn-ghost" wire:click="editZone({{ $z->id }})">Edit</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$editingClassId !== null" title="Edit Tax Class" wireClose="cancelEditClass">
        <form wire:submit.prevent="updateClass" class="space-y-4">
            <x-ui.input label="Name" wire:model="editClassName" required />
            <x-ui.input label="Code" wire:model="editClassCode" required />
            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEditClass">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$editingZoneId !== null" title="Edit Tax Zone" wireClose="cancelEditZone">
        <form wire:submit.prevent="updateZone" class="space-y-4">
            <x-ui.input label="Name" wire:model="editZoneName" required />
            <x-ui.input label="Code" wire:model="editZoneCode" required />
            <x-ui.input label="Country Code" wire:model="editCountryCode" required />
            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEditZone">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
