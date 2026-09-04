<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('CMS Pages') }}</h1>
            <p class="text-sm text-base-content/60">{{ __('Manage static pages built from reusable blocks.') }}</p>
        </div>
        <x-ui.button variant="primary" wire:click="create">{{ __('New Page') }}</x-ui.button>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'CMS Pages' => null]" />

    <x-ui.card>
        <x-ui.table :headers="[__('Title'), __('Status'), __('Template'), '']" :empty="$pages->isEmpty()" emptyMessage="{{ __('No pages yet.') }}">
            @foreach ($pages as $page)
                <tr wire:key="page-{{ $page->id }}">
                    <td>{{ $page->translation()?->title ?? __('(untitled)') }}</td>
                    <td>
                        <x-ui.badge variant="{{ $page->status === 'published' ? 'success' : 'ghost' }}">{{ $page->status }}</x-ui.badge>
                    </td>
                    <td>{{ $page->template }}</td>
                    <td class="text-end">
                        <a href="{{ route('control-center.platform.cms.pages.edit', ['page' => $page->id]) }}" wire:navigate class="btn btn-sm btn-ghost">{{ __('Edit') }}</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$pages" />
    </x-ui.card>
</div>
