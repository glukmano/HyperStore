<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold text-base-content">Affiliate Dashboard</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (! $affiliate)
        <x-ui.alert variant="info">You are not registered as an Affiliate.</x-ui.alert>
    @else
        <x-ui.card title="Overview">
            <p>Status: <x-ui.badge variant="{{ $affiliate->status->value === 'active' ? 'success' : 'warning' }}">{{ ucfirst($affiliate->status->value) }}</x-ui.badge></p>
            <p>Active conversions: {{ $conversionCount }}</p>
            <p>Withdrawable balance: {{ number_format($balances->withdrawableBalanceMinor / 100, 2) }} {{ $affiliate->payout_currency }}</p>
        </x-ui.card>

        <x-ui.card title="Your Referral Links">
            @forelse ($referralCodes as $code)
                <p class="font-mono text-sm">{{ $trackBaseUrl }}/{{ $code->code }}</p>
            @empty
                <p>No referral codes yet — ask an admin to generate one for you.</p>
            @endforelse
        </x-ui.card>

        @if ($affiliate->status->value === 'active' && $balances->withdrawableBalanceMinor > 0)
            <x-ui.card title="Request Payout">
                <x-ui.button wire:click="requestPayout({{ $balances->withdrawableBalanceMinor }})" variant="primary">
                    Request Full Payout ({{ number_format($balances->withdrawableBalanceMinor / 100, 2) }} {{ $affiliate->payout_currency }})
                </x-ui.button>
            </x-ui.card>
        @endif
    @endif
</div>
