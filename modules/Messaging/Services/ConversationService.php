<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use App\Models\User;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Models\ConversationParticipant;

final class ConversationService
{
    public function startBuyerVendorConversation(
        int $tenantId,
        ?int $storeId,
        User $buyer,
        int $vendorId,
        ?string $subject = null,
        ?string $contextType = null,
        ?int $contextId = null,
    ): Conversation {
        $conversation = Conversation::query()->create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'vendor_id' => $vendorId,
            'subject' => $subject,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'status' => Conversation::STATUS_OPEN,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $buyer->id,
            'role' => ConversationParticipant::ROLE_BUYER,
            'joined_at' => now(),
        ]);

        return $conversation;
    }

    public function addVendorStaffParticipant(Conversation $conversation, User $vendorStaffUser): ConversationParticipant
    {
        return ConversationParticipant::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $vendorStaffUser->id],
            ['role' => ConversationParticipant::ROLE_VENDOR_STAFF, 'joined_at' => now()],
        );
    }

    public function addTenantStaffParticipant(Conversation $conversation, User $tenantStaffUser): ConversationParticipant
    {
        return ConversationParticipant::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $tenantStaffUser->id],
            ['role' => ConversationParticipant::ROLE_TENANT_STAFF, 'joined_at' => now()],
        );
    }
}
