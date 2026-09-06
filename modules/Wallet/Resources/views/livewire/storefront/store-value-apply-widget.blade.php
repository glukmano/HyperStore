<div class="space-y-3">
    <h3 class="font-semibold text-base-content">Apply Store Value</h3>

    @if ($errorMessage)
        <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
    @endif

    @if ($session->store_value_applied_minor > 0)
        <x-ui.alert variant="success">
            Applied: {{ number_format($session->store_value_applied_minor / 100, 2) }} {{ $session->currency }}
            <button wire:click="remove" class="link ml-2">Remove</button>
        </x-ui.alert>
    @else
        <div class="flex flex-wrap gap-2">
            @if ($walletBalance !== null && $walletBalance > 0)
                <x-ui.button wire:click="applyWallet" variant="ghost" size="sm">
                    Use Wallet ({{ number_format($walletBalance / 100, 2) }})
                </x-ui.button>
            @endif
            @if ($storeCreditBalance !== null && $storeCreditBalance > 0)
                <x-ui.button wire:click="applyStoreCredit" variant="ghost" size="sm">
                    Use Store Credit ({{ number_format($storeCreditBalance / 100, 2) }})
                </x-ui.button>
            @endif
        </div>

        <form wire:submit="redeemGiftCard" class="flex gap-2">
            <input type="text" wire:model="giftCardCode" class="input input-bordered input-sm flex-1" placeholder="Gift Card code" />
            <x-ui.button type="submit" variant="primary" size="sm">Apply Gift Card</x-ui.button>
        </form>
    @endif
</div>
