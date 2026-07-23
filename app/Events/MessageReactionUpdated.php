<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Message;
use App\Models\User;

class MessageReactionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $reactor;
    public $emoji;
    public $action;

    public function __construct(Message $message, User $reactor, string $emoji, string $action)
    {
        $this->message = $message;
        $this->reactor = $reactor;
        $this->emoji = $emoji;
        $this->action = $action;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.' . $this->message->chat_id),
        ];

        $this->message->loadMissing('chat.participants');
        foreach ($this->message->chat->participants as $participant) {
            $channels[] = new PrivateChannel('user.' . $participant->user_id);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'chat_id' => $this->message->chat_id,
            'reactions' => $this->message->reactions,
            'reactor_id' => $this->reactor->id,
            'emoji' => $this->emoji,
            'action' => $this->action,
        ];
    }

    /**
     * The event name clients should listen for on the wire.
     */
    public function broadcastAs(): string
    {
        return 'MessageReactionUpdated';
    }
}
