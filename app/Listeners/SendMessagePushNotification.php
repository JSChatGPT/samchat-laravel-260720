<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendMessagePushNotification implements ShouldQueue
{
    public function __construct(private PushNotificationService $pushService)
    {
    }

    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $message->loadMissing(['chat.participants', 'chat.group', 'sender']);

        $chat = $message->chat;
        if (!$chat) {
            return;
        }

        $senderName = $this->displayName($message->sender);
        $title = $chat->group ? ($chat->group->group_name . ': ' . $senderName) : $senderName;
        $body = $this->bodyFor($message);

        foreach ($chat->participants as $participant) {
            if ($participant->user_id === $message->sender_id) {
                continue;
            }

            $recipient = User::find($participant->user_id);
            if (!$recipient) {
                continue;
            }

            $this->pushService->sendToUser($recipient, $title, $body, [
                'type' => 'message',
                'chat_id' => $chat->id,
                'message_id' => $message->id,
                // Safe to include ciphertext here even though $body above
                // can't show it — only the participants' devices hold the
                // chat key, so this is no more exposed than the message
                // itself already is at rest. Lets the client decrypt locally
                // and show the *real* text in the notification instead of
                // being stuck with the generic placeholder $body carries.
                'content' => (string) ($message->content ?? ''),
                'encrypted' => ($message->metadata['encrypted'] ?? false) === true ? '1' : '0',
                // Not "message_type" — FCM reserves that exact key name for
                // its own internal use and rejects the whole send if a data
                // payload uses it (confirmed: Kreait\Firebase\Exception\
                // InvalidArgumentException, "'message_type' is a reserved
                // word").
                'msg_type' => $message->message_type,
            ]);
        }
    }

    private function displayName(?User $user): string
    {
        if (!$user) {
            return 'Someone';
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $user->username ?? ($name !== '' ? $name : 'Someone');
    }

    private function bodyFor($message): string
    {
        // The server never has the E2EE chat key (that's the point of E2EE)
        // — for an encrypted message, $message->content is ciphertext, and
        // showing it here means the push notification literally displays a
        // base64 hash instead of text. The client decrypts locally (it has
        // the key) and builds its own preview text; the server can only ever
        // show a generic placeholder for these.
        if (($message->metadata['encrypted'] ?? false) === true) {
            return 'New message';
        }

        return match ($message->message_type) {
            'image' => '📷 Photo',
            'video' => '🎥 Video',
            'audio' => '🎤 Voice message',
            'file' => '📎 File',
            'sticker' => (string) ($message->content ?? '') . ' Sticker',
            'call_log' => '📞 Call',
            'payment_request' => '💰 Payment request',
            default => (string) ($message->content ?: 'New message'),
        };
    }
}
