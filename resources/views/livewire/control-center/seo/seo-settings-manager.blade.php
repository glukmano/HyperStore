<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('SEO Settings') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'SEO Settings' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="save" class="space-y-4 max-w-md">
            <label class="label cursor-pointer justify-start gap-3">
                <input type="checkbox" wire:model="blockSearchEngines" class="checkbox" />
                <span class="label-text">{{ __('Block search engines from indexing this store (robots.txt disallow all)') }}</span>
            </label>
            <x-ui.button type="submit" variant="primary">{{ __('Save') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
