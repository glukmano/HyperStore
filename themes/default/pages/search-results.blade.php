<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Home' => route('storefront.home'), 'Search' => null]" />

    <h1 class="text-2xl font-bold">{{ __('Search results for ":query"', ['query' => $query]) }}</h1>

    <form method="GET" class="max-w-md">
        <x-ui.input type="search" name="q" value="{{ $query }}" placeholder="{{ __('Search products…') }}" />
    </form>

    @if (! empty($results->hits))
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ($results->hits as $index => $hit)
                <a href="{{ route('storefront.product', $hit['sku']) }}" wire:navigate
                   wire:click="recordClick({{ $hit['id'] }}, {{ $index }})">
                    <x-ui.card>
                        <div class="font-medium">{{ $hit['name'] }}</div>
                        <div class="text-sm text-base-content/60">{{ $hit['sku'] }}</div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>
    @else
        <x-ui.empty-state message="{{ __('No results found.') }}" />
    @endif
</div>
