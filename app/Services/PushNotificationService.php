<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Throwable;

class PushNotificationService
{
    /**
     * Send a push notification to every device registered for a user.
     * No-ops (with a logged warning) if Firebase isn't configured, so the
     * app keeps working normally in environments without push set up.
     *
     * @param  array<string, mixed>  $data  string-keyed, string-valued payload merged into the FCM data payload
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->deviceTokens->pluck('token')->all();

        if (empty($tokens)) {
            return;
        }

        if (empty(config('firebase.projects.app.credentials'))) {
            Log::warning('Push notification skipped: FIREBASE_CREDENTIALS is not configured.');
            return;
        }

        // Deliberately data-only — no ->withNotification(). A message that
        // carries a `notification` payload is, once the app is no longer in
        // the foreground, displayed directly by the OS using its own
        // generic styling, and *never reaches this app's own handler at
        // all* (confirmed: this is exactly why the incoming-call
        // notification's ringtone-loop/ongoing/answer-decline setup and the
        // message notification's "Reply" action only ever worked while the
        // app was already open — that richer notification is built
        // entirely client-side, in fcm_background_handler.dart, and that
        // code was silently never running once backgrounded/killed). A
        // pure data message is always handed to the app itself in every
        // app state instead; title/body ride along in `data` since the
        // client is what builds the visible notification now.
        $message = CloudMessage::new()->withData(array_map('strval', [
            ...$data,
            'title' => $title,
            'body' => $body,
        ]));

        try {
            $report = Firebase::messaging()->sendMulticast($message, $tokens);
        } catch (Throwable $e) {
            Log::error('Push notification send failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            return;
        }

        foreach ($report->failures()->getItems() as $failure) {
            if ($failure->messageTargetWasInvalid() || $failure->messageWasSentToUnknownToken()) {
                $user->deviceTokens()->where('token', $failure->target()->value())->delete();
            }
        }
    }
}
