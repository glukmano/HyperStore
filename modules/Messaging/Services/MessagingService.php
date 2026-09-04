<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Messaging\Events\MessageSent;
use Modules\Messaging\Exceptions\ConversationAuthorizationException;
use Modules\Messaging\Exceptions\ConversationClosedException;
use Modules\Messaging\Exceptions\MessageRateLimitExceededException;
use Modules\Messaging\Models\Conversation;
use Modules\Messaging\Models\ConversationParticipant;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Models\MessageAttachment;

final class MessagingService
{
    private const int MAX_MESSAGES_PER_MINUTE = 20;

    public function __construct(
        private readonly ConversationPolicy $policy,
    ) {}

    /**
     * @param  list<int>  $attachmentMediaIds
     */
    public function send(Conversation $conversation, User $sender, string $body, array $attachmentMediaIds = []): Message
    {
        if (! $this->policy->view($sender, $conversation)) {
            throw new ConversationAuthorizationException('You are not a participant in this conversation.');
        }

        if ($conversation->status !== Conversation::STATUS_OPEN) {
            throw new ConversationClosedException('This conversation is closed.');
        }

        $rateLimitKey = 'messaging-send:'.$sender->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_MESSAGES_PER_MINUTE)) {
            throw new MessageRateLimitExceededException('Too many messages sent — please slow down.');
        }
        RateLimiter::hit($rateLimitKey, 60);

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachmentMediaIds): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'body' => $body,
                'sent_at' => now(),
            ]);

            foreach ($attachmentMediaIds as $mediaId) {
                MessageAttachment::query()->create([
                    'message_id' => $message->id,
                    'media_id' => $mediaId,
                    'created_at' => now(),
                ]);
            }

            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        // Broadcast only after the transaction above has actually
        // committed — persistence-before-broadcast, never the reverse.
        DB::afterCommit(function () use ($message): void {
            $message->load('attachments');
            MessageSent::dispatch($message);
        });

        return $message;
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now()]);
    }

    public function unreadCount(Conversation $conversation, User $user): int
    {
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant === null) {
            return 0;
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_user_id', '!=', $user->id)
            ->when($participant->last_read_at !== null, fn ($q) => $q->where('sent_at', '>', $participant->last_read_at))
            ->count();
    }

    public function close(Conversation $conversation, User $user): Conversation
    {
        if (! $this->policy->close($user, $conversation)) {
            throw new ConversationAuthorizationException('You are not a participant in this conversation.');
        }

        $conversation->update(['status' => Conversation::STATUS_CLOSED]);

        return $conversation;
    }
}
