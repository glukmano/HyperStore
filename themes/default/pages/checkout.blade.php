<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Checkout') }}</h1>

    <ul class="steps w-full">
        @foreach(['customer' => __('Customer'), 'address' => __('Address'), 'shipping' => __('Shipping'), 'review' => __('Review')] as $key => $label)
            <li class="step {{ array_search($step, array_keys(['customer' => 1, 'address' => 2, 'shipping' => 3, 'review' => 4])) >= array_search($key, array_keys(['customer' => 1, 'address' => 2, 'shipping' => 3, 'review' => 4])) ? 'step-primary' : '' }}">{{ $label }}</li>
        @endforeach
    </ul>

    @if($checkoutSessionId === null)
        <x-ui.card>
            <p class="text-base-content/70 mb-4">{{ __('Start checkout from your cart.') }}</p>
            <x-ui.button variant="primary" wire:click="startCheckout">{{ __('Start Checkout') }}</x-ui.button>
        </x-ui.card>
    @elseif($step === 'customer')
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.input label="{{ __('Email') }}" type="email" wire:model="email" :error="$errors->first('email')" />
                <x-ui.input label="{{ __('First name') }}" wire:model="firstName" :error="$errors->first('firstName')" />
                <x-ui.input label="{{ __('Last name') }}" wire:model="lastName" :error="$errors->first('lastName')" />
                <x-ui.input label="{{ __('Phone') }}" wire:model="phone" />
                <x-ui.button variant="primary" wire:click="submitCustomer">{{ __('Continue') }}</x-ui.button>
            </div>
        </x-ui.card>
    @elseif($step === 'address')
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.input label="{{ __('Recipient') }}" wire:model="recipient" :error="$errors->first('recipient')" />
                <x-ui.input label="{{ __('Street') }}" wire:model="street" :error="$errors->first('street')" />
                <x-ui.input label="{{ __('City') }}" wire:model="city" :error="$errors->first('city')" />
                <x-ui.input label="{{ __('Postal code') }}" wire:model="postalCode" />
                <x-ui.input label="{{ __('Country code (ISO-2)') }}" wire:model="countryCode" :error="$errors->first('countryCode')" />
                <x-ui.button variant="primary" wire:click="submitAddress">{{ __('Continue') }}</x-ui.button>
            </div>
        </x-ui.card>
    @elseif($step === 'shipping')
        <x-ui.card>
            <div class="space-y-3">
                @forelse($shippingRates as $rate)
                    <label class="flex items-center gap-3 border border-base-300 rounded-box p-3 cursor-pointer">
                        <input type="radio" name="rate" wire:model="selectedRateId" value="{{ $rate['id'] ?? '' }}" class="radio" />
                        <span>{{ $rate['label'] ?? ($rate['method'] ?? 'Shipping option') }}</span>
                    </label>
                @empty
                    <x-ui.alert variant="warning">{{ __('No shipping methods available for this address.') }}</x-ui.alert>
                @endforelse
                <x-ui.button variant="primary" wire:click="submitShipping">{{ __('Continue') }}</x-ui.button>
            </div>
        </x-ui.card>
    @elseif($step === 'review')
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.alert variant="info">{{ __('Payment collection is finalized after order confirmation.') }}</x-ui.alert>
                <x-ui.button variant="primary" wire:click="placeOrder">{{ __('Place Order') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
