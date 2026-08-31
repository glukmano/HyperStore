@props(['variant' => 'info'])

@php
    $class = match($variant) {
        'success' => 'alert-success',
        'warning' => 'alert-warning',
        'error'   => 'alert-error',
        default   => 'alert-info',
    };
@endphp

<div role="alert" {{ $attributes->merge(['class' => "alert {$class}"]) }}>
    {{ $slot }}
</div>
