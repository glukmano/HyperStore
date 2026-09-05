<div class="mx-auto max-w-lg space-y-4">
    <h1 class="text-xl font-bold text-base-content">Become an Affiliate</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    @if ($existing)
        <x-ui.card title="Your Application">
            <p>Status: <x-ui.badge variant="{{ $existing->status->value === 'active' ? 'success' : 'warning' }}">{{ ucfirst($existing->status->value) }}</x-ui.badge></p>
        </x-ui.card>
    @else
        <x-ui.card title="Application">
            <form wire:submit="apply" class="space-y-4">
                <x-ui.input wire:model="display_name" label="Display Name" error="{{ $errors->first('display_name') }}" />
                <x-ui.input wire:model="payout_currency" label="Payout Currency" placeholder="USD" error="{{ $errors->first('payout_currency') }}" />
                <x-ui.button type="submit" variant="primary">Apply</x-ui.button>
            </form>
        </x-ui.card>
    @endif
</div>
