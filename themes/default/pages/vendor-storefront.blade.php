<div class="space-y-6">
    @if($vendor === null)
        <x-ui.empty-state message="{{ __('This vendor storefront could not be found.') }}" />
    @else
        <x-ui.card>
            <h1 class="text-2xl font-bold">{{ $vendor->name }}</h1>
            @if(is_array($profile) && ! empty($profile['description']))
                <p class="text-base-content/70 mt-2">{{ $profile['description'] }}</p>
            @endif
        </x-ui.card>
    @endif
</div>
