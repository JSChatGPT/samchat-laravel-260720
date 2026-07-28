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

        $chat = $call->chat_id ? Chat::with('participants', 'group')->find($call->chat_id) : null;
        $groupName = $chat?->group?->group_name;

        // For a group call the native incoming-call UI (Android Telecom's
        // caller-display-name — see SamChatConnection.setCallerDisplayName,
        // fed straight from this payload's caller_name) only has one big
        // identity string to show. WhatsApp leads with the group's name
        // there, not the individual caller's, so a group call rings with
        // "Group Name" up top and the actual caller mentioned in the body —
        // otherwise the group calling anywhere except the live in-app UI
        // (which already reads chat.group via CallRecord.title()) never
        // reveals which group is calling at all.
        $body = $groupName
            ? "{$callerName} is calling in {$groupName}"
            : $callerName . ' is calling...';

        $data = [
            'type' => 'incoming_call',
            'call_id' => $call->id,
            'call_type' => $call->call_type,
            'caller_id' => $call->caller_id,
            'caller_name' => $groupName ?? $callerName,
            'caller_photo' => ($groupName ? $chat->group->group_image_url : null) ?? $caller->photo_url ?? '',
            'chat_id' => $call->chat_id ?? '',
        ];

        $recipientIds = [];
        if ($chat) {
            foreach ($chat->participants as $participant) {
                if ($participant->user_id !== $call->caller_id) {
                    $recipientIds[] = $participant->user_id;
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
