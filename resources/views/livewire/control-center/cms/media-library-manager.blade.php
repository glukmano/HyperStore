<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Media Library') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Media Library' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        @if($media->isEmpty())
            <x-ui.empty-state message="{{ __('No banner media uploaded yet.') }}" />
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach($media as $file)
                    <div class="border border-base-300 rounded-box p-2 space-y-2" wire:key="media-{{ $file->id }}">
                        <img src="{{ $file->getUrl() }}" alt="{{ $file->file_name }}" class="rounded aspect-square object-cover w-full" />
                        <p class="text-xs truncate">{{ $file->file_name }}</p>
                        <x-ui.button size="xs" variant="danger" wire:click="delete({{ $file->id }})">{{ __('Delete') }}</x-ui.button>
                    </div>
                @endforeach
            </div>
            <div class="mt-4">
                <x-ui.pagination :paginator="$media" />
            </div>
        @endif
    </x-ui.card>
</div>
