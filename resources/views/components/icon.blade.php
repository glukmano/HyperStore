@props(['name', 'class' => 'w-5 h-5 inline-block'])

@php
    // Pre-Production Readiness icon cleanup: self-hosted Lucide SVGs
    // (resources/icons/*.svg, sourced from the lucide-static npm package,
    // ISC-licensed) — no CDN dependency, no new runtime JS. `name` is
    // never user input; it is always a fixed string literal from a
    // NavigationItem registration or Blade view, so no path-safety
    // handling is required, but we still fail soft (render nothing)
    // rather than leak a filesystem error if a name is ever mistyped.
    $iconPath = resource_path('icons/'.$name.'.svg');
    $svg = null;
    if (is_file($iconPath)) {
        $raw = (string) file_get_contents($iconPath);
        // The source file hardcodes width="24" height="24" and its own
        // class list — strip both so the caller's size/color utility
        // classes (via currentColor on stroke) are what actually apply.
        $raw = preg_replace('/\s(width|height|class)="[^"]*"/', '', $raw, 3);
        $svg = preg_replace('/<svg/', '<svg class="'.e($class).'"', $raw, 1);
    }
@endphp

@if ($svg)
    {!! $svg !!}
@endif
