<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Search Analytics') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Search Analytics' => null]" />

    <x-ui.stats :items="[
        ['label' => __('Searches (30d)'), 'value' => $totalSearches],
        ['label' => __('Result Clicks (30d)'), 'value' => $totalClicks],
    ]" />

    <x-ui.card :title="__('Top Queries (30 days)')">
        <x-ui.table :headers="[__('Query'), __('Count'), __('Total Results')]" :empty="$topQueries->isEmpty()" emptyMessage="{{ __('No searches recorded yet.') }}">
            @foreach($topQueries as $row)
                <tr wire:key="top-{{ $row->normalized_query }}">
                    <td>{{ $row->normalized_query }}</td>
                    <td>{{ $row->query_count }}</td>
                    <td>{{ $row->total_results }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('No-Result Queries (30 days)')">
        <x-ui.table :headers="[__('Query'), __('Count')]" :empty="$noResultQueries->isEmpty()" emptyMessage="{{ __('No zero-result searches — nice.') }}">
            @foreach($noResultQueries as $row)
                <tr wire:key="noresult-{{ $row->normalized_query }}">
                    <td>{{ $row->normalized_query }}</td>
                    <td>{{ $row->query_count }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
