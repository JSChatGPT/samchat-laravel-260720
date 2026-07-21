<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat_id;
    public $user_id;
    public $is_typing;

    public function __construct(string $chat_id, string $user_id, bool $is_typing = true)
    {
        $this->chat_id = $chat_id;
        $this->user_id = $user_id;
        $this->is_typing = $is_typing;
    }

    public function broadcastOn(): array
    {
        // Also broadcast on each participant's own user channel (mirroring
        // MessageSent) so the chat list can show a "typing…" preview for a
        // chat that isn't currently open — the chat-specific channel alone
        // only reaches someone already inside that chat screen.
        $channels = [
            new PrivateChannel('chat.' . $this->chat_id),
        ];

        $chat = \App\Models\Chat::with('participants')->find($this->chat_id);
        if ($chat) {
            foreach ($chat->participants as $participant) {
                $channels[] = new PrivateChannel('user.' . $participant->user_id);
            }
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chat_id,
            'user_id' => $this->user_id,
            'is_typing' => $this->is_typing,
        ];
    }

    /**
     * The event name clients should listen for on the wire.
     */
    public function broadcastAs(): string
    {
        return 'UserTyping';
    }
}
