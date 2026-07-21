<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class IncomingCall implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public $call;

    public function __construct($call)
    {
        $this->call = $call;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->call->chat_id) {
            // Group call: broadcast to each participant's private channel (except the caller)
            $chat = \App\Models\Chat::with('participants')->find($this->call->chat_id);
            if ($chat) {
                $channels = [];
                foreach ($chat->participants as $participant) {
                    if ($participant->user_id !== $this->call->caller_id) {
                        $channels[] = new PrivateChannel('user.' . $participant->user_id);
                    }
                }
                \Illuminate\Support\Facades\Log::info('IncomingCall::broadcastOn (via chat)', [
                    'call_id' => $this->call->id,
                    'chat_id' => $this->call->chat_id,
                    'caller_id' => $this->call->caller_id,
                    'participant_ids' => $chat->participants->pluck('user_id')->all(),
                    'resolved_channels' => array_map(fn($c) => (string) $c, $channels),
                ]);
                return $channels;
            }
        }

        $channels = [
            new PrivateChannel('user.' . $this->call->receiver_id),
        ];
        \Illuminate\Support\Facades\Log::info('IncomingCall::broadcastOn (direct receiver_id)', [
            'call_id' => $this->call->id,
            'receiver_id' => $this->call->receiver_id,
            'resolved_channels' => array_map(fn($c) => (string) $c, $channels),
        ]);
        return $channels;
    }

    /**
     * The event name clients should listen for on the wire.
     */
    public function broadcastAs(): string
    {
        return 'IncomingCall';
    }
}
