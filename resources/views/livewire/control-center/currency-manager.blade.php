<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Currencies</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card title="Add Currency">
        <form wire:submit="createCurrency" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="code" label="ISO 4217 Code" placeholder="e.g. CHF" error="{{ $errors->first('code') }}" />
            <x-ui.input wire:model="name" label="Name" placeholder="e.g. Swiss Franc" error="{{ $errors->first('name') }}" />
            <x-ui.input wire:model="symbol" label="Symbol" placeholder="e.g. CHF" error="{{ $errors->first('symbol') }}" />
            <x-ui.input type="number" wire:model="decimals" label="Decimals" error="{{ $errors->first('decimals') }}" />
            <x-ui.checkbox wire:model="is_active" label="Active" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Add Currency</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Registered Currencies">
        <x-ui.table :headers="['Code', 'Name', 'Symbol', 'Decimals', 'Default', 'Status', '']" :empty="$currencies->isEmpty()" emptyMessage="No currencies yet.">
            @foreach ($currencies as $currency)
                <tr wire:key="currency-{{ $currency->id }}">
                    <td>{{ $currency->code }}</td>
                    <td>{{ $currency->name }}</td>
                    <td>{{ $currency->symbol }}</td>
                    <td>{{ $currency->decimals }}</td>
                    <td>@if($currency->is_default)<x-ui.badge variant="primary">Default</x-ui.badge>@endif</td>
                    <td><x-ui.badge variant="{{ $currency->is_active ? 'success' : 'ghost' }}">{{ $currency->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                    <td>
                        @if($currency->is_active && ! $currency->is_default)
                            <x-ui.button wire:click="deactivateCurrency({{ $currency->id }})" variant="ghost" size="sm">Deactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
