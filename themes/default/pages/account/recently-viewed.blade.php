<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-4">
        <h1 class="text-2xl font-bold">{{ __('Recently Viewed') }}</h1>

        @if($items->isEmpty())
            <x-ui.empty-state message="{{ __('No recently viewed products yet.') }}" />
        @else
            <x-ui.table :headers="[__('Product'), __('Viewed')]">
                @foreach($items as $item)
                    <tr wire:key="rv-{{ $item->id }}">
                        <td>
                            @if($item->product)
                                <a href="{{ route('storefront.product', $item->product->sku) }}" wire:navigate class="link">{{ $item->product->name }}</a>
                            @else
                                <span class="text-base-content/50">{{ __('No longer available') }}</span>
                            @endif
                        </td>
                        <td>{{ $item->viewed_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </div>
</div>
