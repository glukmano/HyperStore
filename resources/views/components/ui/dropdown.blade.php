@props(['label' => 'Options', 'align' => 'end'])

<div {{ $attributes->merge(['class' => 'dropdown dropdown-' . $align]) }}>
    <div tabindex="0" role="button" class="btn btn-ghost btn-sm">{{ $label }}</div>
    <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-10 w-52 p-2 shadow-sm border border-base-200">
        {{ $slot }}
    </ul>
</div>
