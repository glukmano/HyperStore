<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Messaging\Exceptions\ConversationAuthorizationException;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Services\ConversationPolicy;
use Modules\Messaging\Services\MessagingService;

/**
 * Reverb broadcasts MessageSent for real-time delivery to any JS listener
 * subscribed via Echo; this component additionally polls every 5s
 * (wire:poll) so the thread stays correct and usable even before a
 * dedicated Echo/JS wire-up ships — the backend's persistence-then-broadcast
 * guarantee is real either way.
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
