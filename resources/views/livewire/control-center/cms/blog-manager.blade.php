<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Blog') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Blog' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.table :headers="[__('Title'), __('Status'), __('Published'), '']" :empty="$posts->isEmpty()" emptyMessage="{{ __('No blog posts yet.') }}">
            @foreach ($posts as $post)
                <tr wire:key="post-{{ $post->id }}">
                    <td>{{ $post->translation('en')?->title }}</td>
                    <td><x-ui.badge>{{ ucfirst($post->status) }}</x-ui.badge></td>
                    <td>{{ $post->published_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="text-end">
                        @if($post->status !== 'published')
                            <x-ui.button size="sm" variant="success" wire:click="publish({{ $post->id }})">{{ __('Publish') }}</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$posts" />
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('New Post') }}</h2>
        <form wire:submit="create" class="space-y-3 max-w-2xl">
            <x-ui.input wire:model="title" label="{{ __('Title') }}" />
            <x-ui.input wire:model="slug" label="{{ __('Slug') }}" :error="$slugError" />
            <x-ui.input wire:model="excerpt" label="{{ __('Excerpt (optional)') }}" />
            <x-ui.textarea wire:model="body" label="{{ __('Body') }}" rows="6" />
            <x-ui.button type="submit" variant="primary">{{ __('Create Post') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
