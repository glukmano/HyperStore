@props(['label' => null, 'error' => null, 'rows' => 4])

<div class="form-control w-full">
    @if($label)
        <label class="label">
            <span class="label-text">{{ $label }}</span>
        </label>
    @endif

    <textarea rows="{{ $rows }}" {{ $attributes->merge(['class' => 'textarea textarea-bordered w-full' . ($error ? ' textarea-error' : '')]) }}>{{ $slot }}</textarea>

    @if($error)
        <label class="label">
            <span class="label-text-alt text-error">{{ $error }}</span>
        </label>
    @endif
</div>
