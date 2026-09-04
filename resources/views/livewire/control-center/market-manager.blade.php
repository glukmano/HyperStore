<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Markets</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Create Market">
        <form wire:submit="createMarket" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="name" label="Name" placeholder="Market name" error="{{ $errors->first('name') }}" />
            <x-ui.input wire:model="code" label="Code" placeholder="e.g. CH" error="{{ $errors->first('code') }}" />
            <x-ui.input wire:model="default_currency_code" label="Default Currency" placeholder="e.g. CHF" error="{{ $errors->first('default_currency_code') }}" />
            <x-ui.input wire:model="default_locale_code" label="Default Locale" placeholder="e.g. en" error="{{ $errors->first('default_locale_code') }}" />
            <x-ui.input wire:model="timezone" label="Timezone" placeholder="e.g. UTC" error="{{ $errors->first('timezone') }}" />
            <x-ui.checkbox wire:model="is_active" label="Active" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Create Market</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Existing Markets">
        <x-ui.table :headers="['Name', 'Code', 'Currency', 'Locale', 'Timezone', 'Status']" :empty="$markets->isEmpty()" emptyMessage="No markets yet.">
            @foreach ($markets as $market)
                <tr wire:key="market-{{ $market->id }}">
                    <td>{{ $market->name }}</td>
                    <td>{{ $market->code }}</td>
                    <td>{{ $market->default_currency_code }}</td>
                    <td>{{ $market->default_locale_code }}</td>
                    <td>{{ $market->timezone }}</td>
                    <td><x-ui.badge variant="{{ $market->is_active ? 'success' : 'ghost' }}">{{ $market->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
