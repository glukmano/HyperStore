<div>
    @if ($service !== null)
        <x-ui.card title="{{ __('Book This Service') }}">
            @if ($errorMessage)
                <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
            @endif

            <p class="text-sm text-base-content/60">{{ __('Duration') }}: {{ $service->duration_minutes }} {{ __('minutes') }}</p>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-3">
                @foreach ($slots as $slot)
                    <label class="border border-base-300 rounded-box p-2 cursor-pointer flex items-center gap-2">
                        <input type="radio" wire:model="selectedSlotId" value="{{ $slot->id }}" class="radio radio-sm" />
                        <span class="text-sm">{{ $slot->starts_at->format('Y-m-d H:i') }}</span>
                    </label>
                @endforeach
            </div>

            <x-ui.button wire:click="bookSlot" variant="primary" class="mt-3">{{ __('Add Booking to Cart') }}</x-ui.button>
        </x-ui.card>
    @endif
</div>
