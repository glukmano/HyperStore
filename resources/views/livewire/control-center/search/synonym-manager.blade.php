<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Search Synonyms') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Search Synonyms' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.table :headers="[__('Locale'), __('Term'), __('Synonyms'), '']" :empty="$synonyms->isEmpty()" emptyMessage="{{ __('No synonym rules yet.') }}">
            @foreach ($synonyms as $synonym)
                <tr wire:key="syn-{{ $synonym->id }}">
                    <td>{{ $synonym->locale }}</td>
                    <td>{{ $synonym->term }}</td>
                    <td>{{ implode(', ', $synonym->synonyms) }}</td>
                    <td class="text-end">
                        <x-ui.button size="sm" variant="danger" wire:click="delete({{ $synonym->id }})">{{ __('Delete') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$synonyms" />
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('New Synonym Rule') }}</h2>
        <p class="text-sm text-base-content/60 mb-3">{{ __('PostgreSQL is authoritative here — a full search reindex restores these into Meilisearch.') }}</p>
        <form wire:submit="create" class="flex items-end gap-2 flex-wrap">
            <x-ui.input wire:model="locale" label="{{ __('Locale') }}" />
            <x-ui.input wire:model="term" label="{{ __('Term') }}" />
            <x-ui.input wire:model="synonymsInput" label="{{ __('Synonyms (comma-separated)') }}" />
            <x-ui.button type="submit" variant="primary">{{ __('Save') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
