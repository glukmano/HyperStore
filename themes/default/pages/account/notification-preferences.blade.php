<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-4">
        <h1 class="text-2xl font-bold">{{ __('Notification Preferences') }}</h1>

        <x-ui.card>
            <form wire:submit="save" class="space-y-4 max-w-md">
                <label class="label cursor-pointer justify-start gap-3">
                    <input type="checkbox" wire:model="priceDropEmail" class="checkbox" />
                    <span class="label-text">{{ __('Email me about price drops') }}</span>
                </label>
                <label class="label cursor-pointer justify-start gap-3">
                    <input type="checkbox" wire:model="backInStockEmail" class="checkbox" />
                    <span class="label-text">{{ __('Email me when items are back in stock') }}</span>
                </label>
                <x-ui.button type="submit" variant="primary">{{ __('Save Preferences') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
