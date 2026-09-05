@php
    $links = [
        'account.wishlist' => __('Wishlist'),
        'account.recently-viewed' => __('Recently Viewed'),
        'account.gift-registries.index' => __('Gift Registries'),
        'account.messages.index' => __('Messages'),
        'account.notifications' => __('Notification Preferences'),
    ];
@endphp
<x-ui.card class="w-full md:w-56 shrink-0">
    <ul class="menu p-0">
        @foreach($links as $routeName => $label)
            <li>
                <a href="{{ route($routeName) }}" wire:navigate class="{{ request()->routeIs($routeName) ? 'active' : '' }}">
                    {{ $label }}
                </a>
            </li>
        @endforeach
    </ul>
</x-ui.card>
