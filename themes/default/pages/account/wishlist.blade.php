<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">{{ __('Wishlist') }}</h1>
            @unless($readOnly)
                <x-ui.button variant="ghost" size="sm" wire:click="generateShareLink">{{ __('Get Share Link') }}</x-ui.button>
            @endunless
        </div>

        @if(! $readOnly && $wishlist->visibility === 'shared' && $wishlist->share_token)
            <x-ui.alert variant="info">
                {{ __('Share link') }}: {{ route('account.wishlist.shared', $wishlist->share_token) }}
            </x-ui.alert>
        @endif

        @if($wishlist->items->isEmpty())
            <x-ui.empty-state message="{{ __('Your wishlist is empty.') }}" />
        @else
            <x-ui.table :headers="[__('Product'), '']">
                @foreach($wishlist->items as $item)
                    <tr wire:key="wishlist-item-{{ $item->id }}">
                        <td>
                            @if($item->product)
                                <a href="{{ route('storefront.product', $item->product->sku) }}" wire:navigate class="link">{{ $item->product->name }}</a>
                            @else
                                <span class="text-base-content/50">{{ __('No longer available') }}</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @unless($readOnly)
                                <x-ui.button variant="ghost" size="sm" wire:click="removeItem({{ $item->product_id }}, {{ $item->variant_id ?? 'null' }})">{{ __('Remove') }}</x-ui.button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </div>
</div>
