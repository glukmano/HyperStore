<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('FAQ') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'FAQ' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.table :headers="[__('Question'), __('Published'), '']" :empty="$faqs->isEmpty()" emptyMessage="{{ __('No FAQ entries yet.') }}">
            @foreach ($faqs as $faq)
                <tr wire:key="faq-{{ $faq->id }}">
                    <td>{{ $faq->translation('en')?->question }}</td>
                    <td>
                        <x-ui.badge variant="{{ $faq->is_published ? 'success' : 'neutral' }}">
                            {{ $faq->is_published ? __('Published') : __('Hidden') }}
                        </x-ui.badge>
                    </td>
                    <td class="text-end">
                        <x-ui.button size="sm" variant="ghost" wire:click="togglePublished({{ $faq->id }})">
                            {{ $faq->is_published ? __('Hide') : __('Publish') }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$faqs" />
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('New FAQ Entry') }}</h2>
        <form wire:submit="create" class="space-y-3 max-w-2xl">
            <x-ui.input wire:model="question" label="{{ __('Question') }}" />
            <x-ui.textarea wire:model="answer" label="{{ __('Answer') }}" rows="4" />
            <x-ui.input wire:model="category" label="{{ __('Category (optional)') }}" />
            <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
