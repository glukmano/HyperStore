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
            <div class="form-control max-w-xs">
                <label class="label"><span class="label-text">{{ __('Editing Locale') }}</span></label>
                <select wire:model.live="locale" class="select select-bordered">
                    @foreach($activeLocales as $activeLocale)
                        <option value="{{ $activeLocale->code }}">{{ $activeLocale->native_name }} ({{ $activeLocale->code }})</option>
                    @endforeach
                </select>
            </div>
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
                <div>
                    <span class="font-medium">{{ $block->block_type }}</span>
                    <span class="text-sm text-base-content/50 ms-2">{{ __('Position') }}: {{ $block->position }}</span>
                </div>
                <div class="space-x-1">
                    <x-ui.button size="xs" variant="ghost" wire:click="moveBlockUp({{ $block->id }})">↑</x-ui.button>
                    <x-ui.button size="xs" variant="ghost" wire:click="moveBlockDown({{ $block->id }})">↓</x-ui.button>
                    <x-ui.button size="xs" variant="danger" wire:click="removeBlock({{ $block->id }})">{{ __('Remove') }}</x-ui.button>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('No blocks yet')" />
        @endforelse

        <div class="mt-4 space-y-3">
            <h3 class="font-semibold">{{ __('Add Block') }}</h3>
            @if($blockError)
                <x-ui.alert variant="danger">{{ $blockError }}</x-ui.alert>
            @endif
            <div class="form-control max-w-xs">
                <label class="label"><span class="label-text">{{ __('Block Type') }}</span></label>
                <select wire:model="newBlockType" class="select select-bordered">
                    @foreach(array_keys($availableBlockTypes) as $key)
                        <option value="{{ $key }}">{{ $key }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.textarea wire:model="newBlockConfigJson" label="{{ __('Config (JSON)') }}" rows="4" />
            <x-ui.button wire:click="addBlock" variant="primary">{{ __('Add Block') }}</x-ui.button>
        </div>
    </x-ui.card>
</div>
