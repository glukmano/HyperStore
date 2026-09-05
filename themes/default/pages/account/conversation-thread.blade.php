{{-- wire:poll is a degradation fallback only (e.g. window.Echo never
     connected because Reverb is down) — the primary realtime update path
     is the private Reverb channel subscription wired in
     ConversationThread::onMessageBroadcast(). --}}
<div class="flex flex-col md:flex-row gap-6" wire:poll.15s>
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-4">
        <h1 class="text-2xl font-bold">{{ $conversation->subject ?? __('Conversation') }}</h1>

        <div class="space-y-3 max-h-[28rem] overflow-y-auto">
            @foreach($messages as $message)
                <div class="chat {{ $message->sender_user_id === auth()->id() ? 'chat-end' : 'chat-start' }}" wire:key="msg-{{ $message->id }}">
                    <div class="chat-header text-xs opacity-60">{{ $message->sender->name }} · {{ $message->sent_at->diffForHumans() }}</div>
                    <div class="chat-bubble">{{ $message->body }}</div>
                    @foreach($message->attachments as $attachment)
                        <a href="{{ route('storefront.message-attachments.show', $attachment) }}" class="link text-xs">{{ __('Attachment') }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>

        @if($conversation->status === 'open')
            <form wire:submit="send" class="flex gap-2">
                <x-ui.textarea wire:model="body" rows="2" class="flex-1" />
                <x-ui.button type="submit" variant="primary">{{ __('Send') }}</x-ui.button>
            </form>
        @else
            <x-ui.alert variant="info">{{ __('This conversation is closed.') }}</x-ui.alert>
        @endif
    </div>
</div>
