@props(['items' => []])

<div {{ $attributes->merge(['class' => 'breadcrumbs text-sm']) }}>
    <ul>
        @foreach($items as $label => $url)
            <li>
                @if($url && ! $loop->last)
                    <a href="{{ $url }}" wire:navigate>{{ $label }}</a>
                @else
                    <span>{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</div>
