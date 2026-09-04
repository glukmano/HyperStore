@props(['label' => null, 'name' => null])

<label class="label cursor-pointer justify-start gap-3">
    <input type="radio" name="{{ $name }}" {{ $attributes->merge(['class' => 'radio']) }}>
    @if($label)
        <span class="label-text">{{ $label }}</span>
    @endif
</label>
