<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Services\ConversationPolicy;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Phase-17: private conversation channel — authorization delegates to the
// exact same ConversationPolicy Livewire components use, never duplicated.
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::query()->find($conversationId);

    if ($conversation === null) {
        return false;
    }

    return app(ConversationPolicy::class)->view($user, $conversation)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
