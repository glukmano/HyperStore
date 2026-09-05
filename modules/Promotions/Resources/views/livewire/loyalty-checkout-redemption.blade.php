<div>
    @if ($visible || $appliedCode !== null)
        <x-ui.card title="{{ __('Loyalty Points') }}">
            @if ($errorMessage ?? false)
                <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
            @endif

            @if ($appliedCode !== null)
                <div class="flex items-center justify-between">
                    <p class="text-sm">{{ __('Loyalty points redemption applied to this order.') }}</p>
                    <x-ui.button wire:click="cancelRedemption" variant="ghost" size="sm">{{ __('Remove') }}</x-ui.button>
                </div>
            @else
                <div class="space-y-3">
                    <p class="text-sm text-base-content/70">
                        {{ __('You have :points points available, worth :value per point.', ['points' => number_format($available), 'value' => number_format(($redemptionValueMinor ?? 0) / 100, 2).' '.($currency ?? '')]) }}
                    </p>
                    <div class="flex items-end gap-2">
                        <x-ui.input type="number" label="{{ __('Points to redeem') }}" wire:model="pointsToRedeem" min="1" :max="$available" />
                        <x-ui.button wire:click="redeem" variant="primary">{{ __('Redeem') }}</x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
