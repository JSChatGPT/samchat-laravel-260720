<?php

namespace App\Events;

use App\Models\Chat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A device that just registered (reinstall, new phone, web login) but
 * lacks a grant for an already-encrypted chat's key asks every other
 * participant's devices to reseal it — see ChatKeyController::requestGrant
 * and the client's E2eeService.ensureChatKeyAvailable/handleGrantRequest.
 * Any currently-connected device that already holds the key responds by
 * calling the same self-heal endpoint the opportunistic on-send path uses.
 */
class ChatKeyGrantRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chatId;
    public string $requestingUserId;

    public function __construct(string $chatId, string $requestingUserId)
    {
        $this->chatId = $chatId;
        $this->requestingUserId = $requestingUserId;
    }

    /**
     * Deliberately includes the requesting user's OWN channel (not just
     * every other participant's) — the most common trigger for this event
     * is that very user switching devices (new phone, web login), so
     * another one of THEIR OWN sessions is often the one holding the key.
     * The handler on the receiving end (E2eeService.handleGrantRequest) is
     * a no-op on any device that doesn't itself hold the key, including the
     * requesting device echoing its own request back to itself.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $chat = Chat::with('participants')->find($this->chatId);
        if (!$chat) {
            return [];
        }

        return array_map(
            fn ($participant) => new PrivateChannel('user.' . $participant->user_id),
            $chat->participants->all(),
        );
    }

    public function broadcastAs(): string
    {
        return 'ChatKeyGrantRequested';
    }

    public function broadcastWith(): array
    {
        return ['chat_id' => $this->chatId];
    }
}
