@props(['label' => null, 'error' => null])

<div class="form-control w-full">
    @if($label)
        <label class="label">
            <span class="label-text">{{ $label }}</span>
        </label>
    @endif

    <input {{ $attributes->merge(['class' => 'input input-bordered w-full' . ($error ? ' input-error' : '')]) }}>

    @if($error)
        <label class="label">
            <span class="label-text-alt text-error">{{ $error }}</span>
        </label>
    @endif
</div>
