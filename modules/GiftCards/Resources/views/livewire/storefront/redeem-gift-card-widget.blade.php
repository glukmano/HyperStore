<div class="space-y-4">
    <h2 class="text-lg font-semibold text-base-content">Redeem a Gift Card</h2>

    @if ($redeemError)
        <x-ui.alert variant="error">{{ $redeemError }}</x-ui.alert>
    @endif
    @if ($redeemSuccessMessage)
        <x-ui.alert variant="success">{{ $redeemSuccessMessage }}</x-ui.alert>
    @endif

    <form wire:submit="redeem" class="flex gap-2">
        <input type="text" wire:model="code" class="input input-bordered flex-1" placeholder="Enter Gift Card code" />
        <x-ui.button type="submit" variant="primary">Redeem</x-ui.button>
    </form>
    @error('code') <span class="text-error text-sm">{{ $message }}</span> @enderror
</div>
