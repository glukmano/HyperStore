<div class="max-w-md mx-auto space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Track Your Order') }}</h1>

    <x-ui.card>
        <div class="space-y-4">
            <x-ui.input label="{{ __('Order number') }}" wire:model="orderNumber" :error="$errors->first('orderNumber')" />
            <x-ui.input label="{{ __('Email') }}" type="email" wire:model="email" :error="$errors->first('email')" />
            <x-ui.button variant="primary" wire:click="lookup">{{ __('Find my order') }}</x-ui.button>
        </div>
    </x-ui.card>

    @if($searched)
        @if($foundOrder)
            <x-ui.card :title="__('Order').' '.$foundOrder->order_number">
                <p class="text-sm text-base-content/60">{{ __('Status') }}: {{ $foundOrder->order_status }}</p>
            </x-ui.card>
        @else
            <x-ui.alert variant="warning">{{ __('No matching order found.') }}</x-ui.alert>
        @endif
    @endif
</div>
