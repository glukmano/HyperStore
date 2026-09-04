@props(['message' => 'Nothing here yet.', 'icon' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 py-12 text-center text-base-content/60']) }}>
    @if($icon)
        <span class="text-3xl">{{ $icon }}</span>
    @endif
    <p>{{ $message }}</p>
    @isset($action)
        <div class="mt-2">{{ $action }}</div>
    @endisset
</div>
