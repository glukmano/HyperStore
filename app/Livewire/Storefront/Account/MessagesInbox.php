<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Messaging\Models\ConversationParticipant;
use Modules\Messaging\Services\MessagingService;

class MessagesInbox extends Component
{
    public function render(MessagingService $messagingService): View
    {
        /** @var User $user */
        $user = auth()->user();

        $participations = ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->with('conversation.vendor')
            ->get();

        $conversations = $participations
            ->pluck('conversation')
            ->filter()
            ->sortByDesc(fn ($c) => $c->last_message_at)
            ->values();

        $unreadCounts = $conversations->mapWithKeys(
            fn ($c) => [$c->id => $messagingService->unreadCount($c, $user)]
        );

        return view('theme::pages.account.messages-index', [
            'conversations' => $conversations,
            'unreadCounts' => $unreadCounts,
        ])->layout('theme::layouts.app', ['title' => __('Messages')]);
    }
}
