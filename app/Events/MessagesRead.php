<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat_id;
    public $message_ids;

    public function __construct(string $chat_id, array $message_ids)
    {
        $this->chat_id = $chat_id;
        $this->message_ids = $message_ids;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.' . $this->chat_id),
        ];

        // Without this, the sender only ever sees the read tick flip live
        // if they happen to have this exact chat open (subscribed to the
        // chat.{id} channel above) — everyone just sitting on the inbox
        // list, like MessageSent/MessageReactionUpdated, needs their own
        // user.{id} channel too.
        $chat = \App\Models\Chat::with('participants')->find($this->chat_id);
        foreach ($chat?->participants ?? [] as $participant) {
            $channels[] = new PrivateChannel('user.' . $participant->user_id);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'message_ids' => $this->message_ids,
            'status' => 'read',
        ];
    }

    /**
     * The event name clients should listen for on the wire.
     */
    public function broadcastAs(): string
    {
        return 'MessagesRead';
    }
}
