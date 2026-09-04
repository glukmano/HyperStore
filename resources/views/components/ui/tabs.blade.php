@props(['tabs' => [], 'active' => null, 'switchAction' => 'setTab'])

<div role="tablist" {{ $attributes->merge(['class' => 'tabs tabs-bordered']) }}>
    @foreach($tabs as $key => $label)
        <a role="tab"
           wire:click="{{ $switchAction }}('{{ $key }}')"
           class="tab {{ (string) $active === (string) $key ? 'tab-active' : '' }}"
        >{{ $label }}</a>
    @endforeach
</div>
