<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('Edit Page') }}</h1>
            <x-ui.badge variant="{{ $page->status === 'published' ? 'success' : 'ghost' }}">{{ $page->status }}</x-ui.badge>
        </div>
        <div class="space-x-2">
            <x-ui.button variant="primary" wire:click="save">{{ __('Save') }}</x-ui.button>
            @if ($page->status !== 'published')
                <x-ui.button variant="success" wire:click="publish">{{ __('Publish') }}</x-ui.button>
            @endif
        </div>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'CMS Pages' => route('control-center.platform.cms.pages.index'), 'Edit' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card :title="__('Content')">
        <div class="space-y-4">
            <x-ui.input wire:model="title" label="{{ __('Title') }}" />
            <x-ui.input wire:model="slug" label="{{ __('Slug') }}" />
            @if ($slugError)
                <x-ui.alert variant="error">{{ $slugError }}</x-ui.alert>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Blocks')">
        @forelse ($blocks as $block)
            <div class="flex items-center justify-between border-b py-2" wire:key="block-{{ $block->id }}">
                <span>{{ $block->block_type }}</span>
                <span class="text-sm text-base-content/50">{{ __('Position') }}: {{ $block->position }}</span>
            </div>
        @empty
            <x-ui.empty-state :title="__('No blocks yet')" />
        @endforelse
    </x-ui.card>
</div>
