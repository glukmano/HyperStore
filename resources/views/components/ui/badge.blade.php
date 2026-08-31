@props(['variant' => 'neutral'])

@php
    $class = match($variant) {
        'primary'   => 'badge-primary',
        'secondary' => 'badge-secondary',
        'accent'    => 'badge-accent',
        'success'   => 'badge-success',
        'warning'   => 'badge-warning',
        'danger'    => 'badge-error',
        'ghost'     => 'badge-ghost',
        default     => 'badge-neutral',
    };
@endphp

<span {{ $attributes->merge(['class' => "badge {$class}"]) }}>
    {{ $slot }}
</span>
