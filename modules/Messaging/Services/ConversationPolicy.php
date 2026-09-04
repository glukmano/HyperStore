<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use App\Models\User;
use Modules\Marketplace\Models\VendorUser;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Models\ConversationParticipant;

/**
 * The single authorization surface for Conversations — both Livewire
 * components and the Reverb private-channel authorization callback
 * (routes/channels.php) call into this, never duplicating the logic.
 */
final class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant === null) {
            return false;
        }

        return match ($participant->role) {
            ConversationParticipant::ROLE_BUYER => true,
            ConversationParticipant::ROLE_VENDOR_STAFF => $this->isActiveVendorStaff($user, $conversation),
            ConversationParticipant::ROLE_TENANT_STAFF => $user->isMemberOfTenant($conversation->tenant_id),
            default => false,
        };
    }

    public function sendMessage(User $user, Conversation $conversation): bool
    {
        if ($conversation->status !== Conversation::STATUS_OPEN) {
            return false;
        }

        return $this->view($user, $conversation);
    }

    public function close(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    private function isActiveVendorStaff(User $user, Conversation $conversation): bool
    {
        if ($conversation->vendor_id === null) {
            return false;
        }

        return VendorUser::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('vendor_id', $conversation->vendor_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
