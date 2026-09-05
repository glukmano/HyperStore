<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Your Cart') }}</h1>

    @if(! $cart || $cart->lines->isEmpty())
        <x-ui.empty-state message="{{ __('Your cart is empty.') }}">
            <x-slot:action>
                <a href="{{ route('storefront.home') }}" wire:navigate>
                    <x-ui.button variant="primary">{{ __('Continue Shopping') }}</x-ui.button>
                </a>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="[__('Product'), __('Quantity'), '']">
            @foreach($cart->lines as $line)
                <tr wire:key="line-{{ $line->id }}">
                    <td>{{ $line->product?->name ?? $line->sku_snapshot ?? ('#'.$line->product_id) }}</td>
                    <td>
                        <input type="number" min="1" value="{{ $line->getQuantityVO()->toInt() }}"
                               wire:change="updateQuantity({{ $line->id }}, $event.target.value)"
                               class="input input-bordered input-sm w-20" />
                    </td>
                    <td class="text-end space-x-2 rtl:space-x-reverse">
                        @auth
                            <x-ui.button variant="ghost" size="sm" wire:click="saveForLater({{ $line->id }})">{{ __('Save for later') }}</x-ui.button>
                        @endauth
                        <x-ui.button variant="ghost" size="sm" wire:click="removeLine({{ $line->id }})">{{ __('Remove') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex items-end gap-2">
                <x-ui.input label="{{ __('Coupon code') }}" wire:model="couponCode" />
                <x-ui.button wire:click="applyCoupon">{{ __('Apply') }}</x-ui.button>
            </div>
            <x-ui.button variant="primary" wire:click="proceedToCheckout">{{ __('Proceed to Checkout') }}</x-ui.button>
        </div>
    @endif

    @auth
        @if($savedItems->isNotEmpty())
            <x-ui.card class="mt-4">
                <h2 class="text-lg font-bold mb-3">{{ __('Saved for Later') }}</h2>
                <x-ui.table :headers="[__('Product'), __('Qty'), '']">
                    @foreach($savedItems as $item)
                        <tr wire:key="saved-{{ $item->id }}">
                            <td>{{ $item->product?->name ?? ('#'.$item->product_id) }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td class="text-end space-x-2 rtl:space-x-reverse">
                                <x-ui.button variant="primary" size="sm" wire:click="moveToCart({{ $item->id }})">{{ __('Move to Cart') }}</x-ui.button>
                                <x-ui.button variant="ghost" size="sm" wire:click="removeSavedItem({{ $item->id }})">{{ __('Remove') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @endauth
</div>
