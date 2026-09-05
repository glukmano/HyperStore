<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Countries</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Add Country">
        <form wire:submit="createCountry" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="iso2" label="ISO 3166-1 alpha-2" placeholder="e.g. CH" error="{{ $errors->first('iso2') }}" />
            <x-ui.input wire:model="iso3" label="ISO 3166-1 alpha-3" placeholder="e.g. CHE" error="{{ $errors->first('iso3') }}" />
            <x-ui.input wire:model="name" label="Name" placeholder="e.g. Switzerland" error="{{ $errors->first('name') }}" />
            <x-ui.input wire:model="native_name" label="Native Name" placeholder="e.g. Schweiz" error="{{ $errors->first('native_name') }}" />
            <x-ui.checkbox wire:model="is_active" label="Active" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Add Country</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Registered Countries">
        <x-ui.table :headers="['ISO2', 'ISO3', 'Name', 'Status', '']" :empty="$countries->isEmpty()" emptyMessage="No countries yet.">
            @foreach ($countries as $country)
                <tr wire:key="country-{{ $country->id }}">
                    <td>{{ $country->iso2 }}</td>
                    <td>{{ $country->iso3 }}</td>
                    <td>{{ $country->name }}</td>
                    <td><x-ui.badge variant="{{ $country->is_active ? 'success' : 'ghost' }}">{{ $country->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                    <td>
                        @if($country->is_active)
                            <x-ui.button wire:click="deactivateCountry({{ $country->id }})" variant="ghost" size="sm">Deactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
