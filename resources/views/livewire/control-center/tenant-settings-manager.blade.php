<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Platform Settings</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.alert variant="info">
        The platform settings service exposes only a get/set API for individual keys — there is no list-all endpoint, so no settings table is shown here. Use the lookup form below to check an existing key's value.
    </x-ui.alert>

    <x-ui.card title="Set Setting">
        <form wire:submit="setSetting" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="key" label="Key" placeholder="e.g. platform.support_email" error="{{ $errors->first('key') }}" />
            <x-ui.input wire:model="value" label="Value" placeholder="Setting value" error="{{ $errors->first('value') }}" />
            <x-ui.checkbox wire:model="is_encrypted" label="Encrypt value" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Save Setting</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Look Up Setting">
        <form wire:submit="lookupSetting" class="flex items-end gap-4">
            <div class="flex-1">
                <x-ui.input wire:model="key" label="Key" placeholder="Key to look up" />
            </div>
            <x-ui.button type="submit" variant="outline">Look Up</x-ui.button>
        </form>

        @if ($lastLookupKey !== null)
            <div class="mt-4 text-sm">
                <span class="font-semibold">{{ $lastLookupKey }}</span>:
                <span class="text-base-content/70">{{ $lastLookupValue ?? '(not set)' }}</span>
            </div>
        @endif
    </x-ui.card>
</div>
