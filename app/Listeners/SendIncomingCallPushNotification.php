<?php

namespace App\Listeners;

use App\Events\IncomingCall;
use App\Models\Chat;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendIncomingCallPushNotification implements ShouldQueue
{
    public function __construct(private PushNotificationService $pushService)
    {
    }

    public function handle(IncomingCall $event): void
    {
        $call = $event->call;
        $caller = $call->caller;
        $callerName = $this->displayName($caller);
        $title = ucfirst($call->call_type) . ' call';
        $body = $callerName . ' is calling...';

        $data = [
            'type' => 'incoming_call',
            'call_id' => $call->id,
            'call_type' => $call->call_type,
            'caller_id' => $call->caller_id,
            'caller_name' => $callerName,
            'caller_photo' => $caller->photo_url ?? '',
            'chat_id' => $call->chat_id ?? '',
        ];

        $recipientIds = [];
        if ($call->chat_id) {
            $chat = Chat::with('participants')->find($call->chat_id);
            if ($chat) {
                foreach ($chat->participants as $participant) {
                    if ($participant->user_id !== $call->caller_id) {
                        $recipientIds[] = $participant->user_id;
                    }
                }
            }
        } elseif ($call->receiver_id) {
            $recipientIds[] = $call->receiver_id;
        }

        foreach (array_unique($recipientIds) as $userId) {
            $recipient = User::find($userId);
            if ($recipient) {
                $this->pushService->sendToUser($recipient, $title, $body, $data);
            }
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
}
