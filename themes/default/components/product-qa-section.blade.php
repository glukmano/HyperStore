<x-ui.card class="mt-6">
    <h2 class="text-xl font-bold mb-4">{{ __('Questions & Answers') }}</h2>

    <div class="space-y-4 mb-6">
        @forelse($questions as $question)
            <div class="border-b border-base-300 pb-4">
                <p class="font-medium">Q: {{ $question->body }}</p>
                <p class="text-xs text-base-content/50">{{ __('Asked by') }} {{ $question->user->name }}</p>

                @foreach($question->answers as $answer)
                    <div class="ms-6 mt-2 ps-3 border-s-2 border-base-300 text-sm">
                        <span class="font-semibold">
                            {{ $answer->is_vendor_answer ? __('Seller') : $answer->user->name }}:
                        </span>
                        {{ $answer->body }}
                    </div>
                @endforeach
            </div>
        @empty
            <x-ui.empty-state message="{{ __('No questions yet.') }}" />
        @endforelse
    </div>

    @auth
        <form wire:submit="ask" class="space-y-3 max-w-lg">
            <x-ui.textarea wire:model="question" label="{{ __('Ask a question about this product') }}" rows="3" />
            <x-ui.button type="submit" variant="primary">{{ __('Submit Question') }}</x-ui.button>
        </form>
    @else
        <a href="{{ route('login') }}" wire:navigate class="link">{{ __('Sign in to ask a question') }}</a>
    @endauth
</x-ui.card>
