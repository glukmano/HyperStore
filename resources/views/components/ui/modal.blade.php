@props(['show' => false, 'title' => null, 'wireClose' => null])

<div {{ $attributes->merge(['class' => 'modal' . ($show ? ' modal-open' : '')]) }}>
    <div class="modal-box">
        @if($title)
            <h3 class="text-lg font-bold mb-4">{{ $title }}</h3>
        @endif

        {{ $slot }}

        @isset($footer)
            <div class="modal-action">
                {{ $footer }}
            </div>
        @endisset
    </div>
    @if($wireClose)
        <div class="modal-backdrop" wire:click="{{ $wireClose }}"></div>
    @else
        <div class="modal-backdrop"></div>
    @endif
</div>
