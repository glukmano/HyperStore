@props(['title' => null, 'compact' => false])

<div {{ $attributes->merge(['class' => 'card bg-base-100 shadow-sm']) }}>
    @if($title)
        <div class="card-body {{ $compact ? 'p-4' : '' }}">
            <h2 class="card-title">{{ $title }}</h2>
            {{ $slot }}
        </div>
    @else
        <div class="card-body {{ $compact ? 'p-4' : '' }}">
            {{ $slot }}
        </div>
    @endif
</div>
