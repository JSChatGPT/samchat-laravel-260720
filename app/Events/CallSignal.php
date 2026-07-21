<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $call_id;
    public $data;

    public function __construct(string $call_id, array $data)
    {
        $this->call_id = $call_id;
        $this->data = $data;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('call.' . $this->call_id),
        ];
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }

    public function broadcastAs(): string
    {
        return $this->data['type'] ?? 'client-webrtc-signal';
    }
}
