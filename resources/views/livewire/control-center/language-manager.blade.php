<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Languages</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card title="Add Locale">
        <form wire:submit="createLanguage" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="code" label="Locale Code" placeholder="e.g. ar, ar-SY, de-CH" error="{{ $errors->first('code') }}" />
            <x-ui.input wire:model="name" label="Name" placeholder="e.g. German" error="{{ $errors->first('name') }}" />
            <x-ui.input wire:model="native_name" label="Native Name" placeholder="e.g. Deutsch" error="{{ $errors->first('native_name') }}" />
            <x-ui.select wire:model="direction" label="Direction">
                <option value="ltr">LTR</option>
                <option value="rtl">RTL</option>
            </x-ui.select>
            <x-ui.checkbox wire:model="is_active" label="Active" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Add Locale</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Registered Locales">
        <x-ui.table :headers="['Code', 'Name', 'Native Name', 'Direction', 'Default', 'Status', '']" :empty="$languages->isEmpty()" emptyMessage="No locales yet.">
            @foreach ($languages as $language)
                <tr wire:key="lang-{{ $language->id }}">
                    <td>{{ $language->code }}</td>
                    <td>{{ $language->name }}</td>
                    <td>{{ $language->native_name }}</td>
                    <td><x-ui.badge variant="neutral">{{ strtoupper($language->direction) }}</x-ui.badge></td>
                    <td>@if($language->is_default)<x-ui.badge variant="primary">Default</x-ui.badge>@endif</td>
                    <td><x-ui.badge variant="{{ $language->is_active ? 'success' : 'ghost' }}">{{ $language->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                    <td>
                        @if($language->is_active && ! $language->is_default)
                            <x-ui.button wire:click="deactivateLanguage({{ $language->id }})" variant="ghost" size="sm">Deactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
