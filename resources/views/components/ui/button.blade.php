@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button'])

@php
    $sizeClass = match($size) {
        'xs'  => 'btn-xs',
        'sm'  => 'btn-sm',
        'lg'  => 'btn-lg',
        'xl'  => 'btn-wide',
        default => '',
    };

    $variantClass = match($variant) {
        'secondary' => 'btn-secondary',
        'accent'    => 'btn-accent',
        'ghost'     => 'btn-ghost',
        'outline'   => 'btn-outline',
        'danger'    => 'btn-error',
        'success'   => 'btn-success',
        'warning'   => 'btn-warning',
        default     => 'btn-primary',
    };
@endphp

<button
    {{ $attributes->merge(['type' => $type, 'class' => "btn {$variantClass} {$sizeClass}"]) }}
>
    {{ $slot }}
</button>
