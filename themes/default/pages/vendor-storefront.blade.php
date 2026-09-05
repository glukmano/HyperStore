<div class="space-y-6">
    @if($vendor === null)
        <x-ui.empty-state message="{{ __('This vendor storefront could not be found.') }}" />
    @else
        <x-ui.card>
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-bold">{{ $vendor->name }}</h1>
                    @if($aggregate['count'] > 0)
                        <p class="text-base-content/70">⭐ {{ number_format($aggregate['average'], 1) }} ({{ $aggregate['count'] }} {{ __('reviews') }})</p>
                    @endif
                </div>
                <x-ui.button wire:click="toggleFollow" variant="ghost" size="sm">
                    {{ $isFollowing ? __('★ Following') : __('☆ Follow this Vendor') }}
                </x-ui.button>
            </div>
            @if(is_array($profile) && ! empty($profile['description']))
                <p class="text-base-content/70 mt-2">{{ $profile['description'] }}</p>
            @endif
        </x-ui.card>

        <livewire:storefront.vendor-reviews-section :vendor-id="$vendor->id" />
    @endif
</div>
