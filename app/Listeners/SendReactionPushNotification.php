<?php

namespace App\Listeners;

use App\Events\MessageReactionUpdated;
use App\Models\Message;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendReactionPushNotification implements ShouldQueue
{
    public function __construct(private PushNotificationService $pushService)
    {
    }

    public function handle(MessageReactionUpdated $event): void
    {
        // WhatsApp only notifies on a reaction being added, and only the
        // original message's author — not the reactor, and not the rest of
        // a group chat.
        if ($event->action !== 'added') {
            return;
        }

        $message = Message::find($event->message->id);
        if (!$message || $message->sender_id === $event->reactor->id) {
            return;
        }

        $recipient = User::find($message->sender_id);
        if (!$recipient) {
            return;
        }

        $reactorName = $this->displayName($event->reactor);

        $this->pushService->sendToUser(
            $recipient,
            "$reactorName reacted {$event->emoji} to your message",
            $this->previewFor($message),
            [
                'type' => 'reaction',
                'chat_id' => $message->chat_id,
                'message_id' => $message->id,
            ],
        );
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $user->username ?? ($name !== '' ? $name : 'Someone');
    }

    private function previewFor(Message $message): string
    {
        if (($message->metadata['encrypted'] ?? false) === true) {
            return '';
        }

        return match ($message->message_type) {
            'image' => '📷 Photo',
            'video' => '🎥 Video',
            'audio' => '🎤 Voice message',
            'file' => '📎 File',
            'sticker' => (string) ($message->content ?? '') . ' Sticker',
            'call_log' => '📞 Call',
            'payment_request' => '💰 Payment request',
            default => (string) ($message->content ?: ''),
        };
    }
}
