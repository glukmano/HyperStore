@props(['label' => null, 'error' => null, 'options' => [], 'placeholder' => null])

<div class="form-control w-full">
    @if($label)
        <label class="label">
            <span class="label-text">{{ $label }}</span>
        </label>
    @endif

    <select {{ $attributes->merge(['class' => 'select select-bordered w-full' . ($error ? ' select-error' : '')]) }}>
        @if($placeholder)
            <option value="" disabled selected>{{ $placeholder }}</option>
        @endif
        {{ $slot }}
    </select>

    @if($error)
        <label class="label">
            <span class="label-text-alt text-error">{{ $error }}</span>
        </label>
    @endif
</div>
