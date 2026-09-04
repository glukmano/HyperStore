<?php

declare(strict_types=1);

namespace Modules\Messaging\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Messaging\Models\Message;

/**
 * Dispatched only via DB::afterCommit() by MessagingService::send(), never
 * before the Message row (and its attachments) are durably persisted —
 * broadcast is notification-of-a-fact, never the fact itself (Master §18:
 * "Persistent application/database data is authoritative; realtime
 * transport is not").
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message $message,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Only the already-persisted, public-safe payload — never raw
     * attachment storage paths, only IDs the client resolves via an
     * authorized, signed-URL endpoint.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'uuid' => $this->message->uuid,
            'conversation_id' => $this->message->conversation_id,
            'sender_user_id' => $this->message->sender_user_id,
            'body' => $this->message->body,
            'sent_at' => $this->message->sent_at->toIso8601String(),
            'attachment_ids' => $this->message->attachments->pluck('id'),
        ];
    }
}
