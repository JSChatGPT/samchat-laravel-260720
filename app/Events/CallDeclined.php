<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class CallDeclined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public $call;
    public $target_id;

    public function __construct($call, $target_id = null)
    {
        $this->call = $call;
        $this->target_id = $target_id;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $target = $this->target_id ?? $this->call->caller_id;
        return [
            new PrivateChannel('user.' . $target),
        ];
    }

    /**
     * The event name clients should listen for on the wire.
     */
    public function broadcastAs(): string
    {
        return 'CallDeclined';
    }
}
