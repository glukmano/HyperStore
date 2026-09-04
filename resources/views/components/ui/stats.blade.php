@props(['items' => []])

{{-- $items: list of ['label' => string, 'value' => string, 'description' => ?string] --}}
<div {{ $attributes->merge(['class' => 'stats shadow-sm bg-base-100 w-full overflow-x-auto']) }}>
    @foreach($items as $item)
        <div class="stat">
            <div class="stat-title">{{ $item['label'] }}</div>
            <div class="stat-value text-2xl">{{ $item['value'] }}</div>
            @if(! empty($item['description']))
                <div class="stat-desc">{{ $item['description'] }}</div>
            @endif
        </div>
    @endforeach
</div>
