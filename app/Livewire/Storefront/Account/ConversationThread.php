<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Messaging\Exceptions\ConversationAuthorizationException;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Services\ConversationPolicy;
use Modules\Messaging\Services\MessagingService;

/**
 * Realtime delivery: Livewire's built-in Laravel Echo integration
 * (js/features/supportLaravelEcho.js) subscribes the browser to the
 * private `conversation.{id}` Reverb channel and, on every `message.sent`
 * broadcast, calls onMessageBroadcast() below via a normal Livewire
 * request — which re-runs render() and re-queries messages straight from
 * Postgres. That makes the update path identical to a plain page
 * refresh: the DB row (not the socket payload) is what ends up on
 * screen, a reconnect/duplicate delivery just re-triggers the same
 * idempotent full re-render (deduplicated by `wire:key="msg-{id}"` in the
 * Blade morph), and an unauthorized user never even completes the Echo
 * subscription (routes/channels.php's `conversation.{id}` callback runs
 * the same ConversationPolicy as this component's own mount() check).
 * wire:poll remains only as a documented degradation fallback for a
 * browser session where window.Echo never connected (e.g. Reverb down).
 */
class ConversationThread extends Component
{
    public Conversation $conversation;

    public string $body = '';

    public function mount(Conversation $conversation, ConversationPolicy $policy): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($policy->view($user, $conversation), 403);

        $this->conversation = $conversation;
    }

    /**
     * Bound to the private Reverb channel for this exact conversation via
     * Livewire's `echo-private:` listener convention — `{conversation.id}`
     * is interpolated against this component's own hydrated state, and
     * `.message.sent` (leading dot) matches MessageSent::broadcastAs()'s
     * un-namespaced custom event name. No payload is trusted from the
     * socket; the method body is intentionally empty because Livewire's
     * own re-render (from render(), below) is what refreshes the thread.
     */
    #[On('echo-private:conversation.{conversation.id},.message.sent')]
    public function onMessageBroadcast(): void
    {
        //
    }

    public function send(MessagingService $messagingService): void
    {
        $this->validate(['body' => 'required|string|min:1|max:5000']);

        /** @var User $user */
        $user = auth()->user();

        try {
            $messagingService->send($this->conversation, $user, $this->body);
            $this->reset('body');
        } catch (ConversationAuthorizationException $e) {
            session()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(MessagingService $messagingService): View
    {
        /** @var User $user */
        $user = auth()->user();

        $messagingService->markRead($this->conversation, $user);

        $messages = $this->conversation->messages()->with('sender', 'attachments')->get();

        return view('theme::pages.account.conversation-thread', ['messages' => $messages])
            ->layout('theme::layouts.app', ['title' => $this->conversation->subject ?? __('Conversation')]);
    }
}
