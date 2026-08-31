<div class="space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <x-ui.card title="Tax Classes">
            <form wire:submit.prevent="createClass" class="flex gap-2 mb-4">
                <input type="text" wire:model="className" placeholder="Name (e.g. Standard)" class="input input-bordered input-sm w-full" required />
                <input type="text" wire:model="classCode" placeholder="Code (standard)" class="input input-bordered input-sm w-full" required />
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
            <ul class="divide-y divide-base-300">
                @foreach ($classes as $c)
                    <li class="py-2 flex justify-between"><span>{{ $c->name }}</span><code class="text-xs">{{ $c->code }}</code></li>
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
                    <li class="py-2 flex justify-between"><span>{{ $z->name }} ({{ $z->country_code }})</span><code class="text-xs">{{ $z->code }}</code></li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>
</div>
