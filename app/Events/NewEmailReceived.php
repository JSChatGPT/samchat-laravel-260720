<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewEmailReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $emailAccountId;
    public $newCount;

    public function __construct(string $userId, string $emailAccountId, int $newCount)
    {
        $this->userId = $userId;
        $this->emailAccountId = $emailAccountId;
        $this->newCount = $newCount;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'email_account_id' => $this->emailAccountId,
            'new_count' => $this->newCount,
        ];
    }

    public function broadcastAs(): string
    {
        return 'NewEmailReceived';
    }
}
