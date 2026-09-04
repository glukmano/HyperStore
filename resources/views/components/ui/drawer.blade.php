@props(['id' => 'app-drawer', 'open' => false])

<div {{ $attributes->merge(['class' => 'drawer lg:drawer-open']) }}>
    <input id="{{ $id }}" type="checkbox" class="drawer-toggle" @if($open) checked @endif />

    <div class="drawer-content flex flex-col">
        {{ $content ?? '' }}
    </div>

    <div class="drawer-side z-40">
        <label for="{{ $id }}" aria-label="close sidebar" class="drawer-overlay"></label>
        {{ $slot }}
    </div>
</div>
