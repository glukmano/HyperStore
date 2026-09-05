<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
     * `clientMessageId` is a caller-generated UUID (e.g. a Livewire
     * component holding it across a network retry). If a message already
     * exists for (conversation_id, sender_user_id, client_message_id), that
     * existing row is returned rather than creating a duplicate — a retried
     * send is idempotent. When omitted, a fresh UUID is generated, matching
     * prior at-most-once-per-call behavior for callers that don't retry.
     *
     * @param  list<int>  $attachmentMediaIds
     */
    public function send(Conversation $conversation, User $sender, string $body, array $attachmentMediaIds = [], ?string $clientMessageId = null): Message
    {
        if (! $this->policy->view($sender, $conversation)) {
            throw new ConversationAuthorizationException('You are not a participant in this conversation.');
        }

        if ($conversation->status !== Conversation::STATUS_OPEN) {
            throw new ConversationClosedException('This conversation is closed.');
        }

        $clientMessageId ??= (string) Str::uuid();

        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_user_id', $sender->id)
            ->where('client_message_id', $clientMessageId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $rateLimitKey = 'messaging-send:'.$sender->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_MESSAGES_PER_MINUTE)) {
            throw new MessageRateLimitExceededException('Too many messages sent — please slow down.');
        }
        RateLimiter::hit($rateLimitKey, 60);

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachmentMediaIds, $clientMessageId): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'client_message_id' => $clientMessageId,
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

    /**
     * Never regresses last_read_at: a WHERE guard means an out-of-order or
     * duplicate markRead (e.g. two browser tabs) can only advance the
     * timestamp forward, never reset it to an earlier value.
     */
    public function markRead(Conversation $conversation, User $user): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('last_read_at')->orWhere('last_read_at', '<', now());
            })
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
