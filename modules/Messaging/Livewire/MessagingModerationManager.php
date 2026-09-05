<?php

declare(strict_types=1);

namespace Modules\Messaging\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Messaging\Models\Conversation;

class MessagingModerationManager extends Component
{
    public string $statusFilter = Conversation::STATUS_OPEN;

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    /**
     * Deliberately bypasses ConversationPolicy/MessagingService::close() —
     * that path is participant self-service (a buyer or vendor-staff
     * closing their own conversation) and requires a ConversationParticipant
     * row. A moderator here is authorized purely by the `messaging.moderate`
     * permission gate below, not by conversation participation.
     */
    public function close(int $conversationId): void
    {
        $this->authorizeModerate();

        $conversation = Conversation::query()->where('tenant_id', $this->tenantId())->findOrFail($conversationId);
        $conversation->update(['status' => Conversation::STATUS_CLOSED]);
        session()->flash('success', 'Conversation closed.');
    }

    public function render(): View|Factory
    {
        $this->authorizeModerate();

        $conversations = Conversation::query()
            ->where('tenant_id', $this->tenantId())
            ->where('status', $this->statusFilter)
            ->with('vendor', 'participants.user')
            ->latest('last_message_at')
            ->paginate(20);

        return view('livewire.control-center.messaging.messaging-moderation-manager', ['conversations' => $conversations])
            ->layout('layouts.control-center', ['title' => 'Messaging Moderation']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeModerate(): void
    {
        if (! auth()->user()?->can('messaging.moderate') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
