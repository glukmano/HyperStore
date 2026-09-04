<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('Dashboard') }}</h1>
            <p class="text-base-content/60 text-sm mt-1">{{ __('Platform overview') }}</p>
        </div>
        <x-ui.badge variant="success">{{ __('LIVE') }}</x-ui.badge>
    </div>

    <x-ui.stats :items="[
        ['label' => __('Locale'), 'value' => strtoupper($locale), 'description' => strtoupper($direction)],
        ['label' => __('Tenant Context'), 'value' => $tenantResolved ? __('Resolved') : __('Unresolved')],
        ['label' => __('Store Context'), 'value' => $storeResolved ? __('Resolved') : __('Unresolved')],
        ['label' => __('Modules'), 'value' => $enabledModules.' / '.($enabledModules + $disabledModules), 'description' => __('enabled')],
    ]" />

    <x-ui.card :title="__('Quick Links')">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            @forelse($navGroups as $group => $items)
                @foreach($items as $item)
                    @if($item->key !== 'dashboard')
                        <a href="{{ $item->url() }}" wire:navigate class="btn btn-outline btn-sm justify-start">
                            @if($item->icon) <span>{{ $item->icon }}</span> @endif
                            {{ $item->label }}
                        </a>
                    @endif
                @endforeach
            @empty
                <p class="text-sm text-base-content/50">{{ __('No sections available yet.') }}</p>
            @endforelse
        </div>
    </x-ui.card>

    <x-ui.alert variant="{{ $direction === 'rtl' ? 'success' : 'info' }}">
        <span>{{ __('RTL/LTR switching is active. Current direction:') }} <strong>{{ strtoupper($direction) }}</strong>.</span>
    </x-ui.alert>

</div>
