@props(['label' => null])

<label class="label cursor-pointer justify-start gap-3">
    <input type="checkbox" {{ $attributes->merge(['class' => 'checkbox']) }}>
    @if($label)
        <span class="label-text">{{ $label }}</span>
    @endif
</label>
