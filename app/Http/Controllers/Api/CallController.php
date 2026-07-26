<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Call;
use App\Models\Chat;
use App\Models\Message;
use App\Events\IncomingCall;
use App\Events\CallAnswered;
use App\Events\CallDeclined;
use App\Events\CallSignal;
use App\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CallController extends Controller
{
    /// Mints a short-lived Cloudflare Realtime TURN credential for the caller
    /// to use as an ICE server — done server-side so the long-lived Cloudflare
    /// API token never ships inside the app itself (unlike the old static
    /// self-hosted TURN password baked into app config). Returns
    /// `{"iceServers": null}` (never an error) when Cloudflare isn't
    /// configured or the request fails, so the app can fall back to
    /// STUN-only rather than the call flow breaking outright.
    public function turnCredentials(Request $request)
    {
        $keyId = config('services.cloudflare_turn.key_id');
        $apiToken = config('services.cloudflare_turn.api_token');

        if (!$keyId || !$apiToken) {
            return response()->json(['iceServers' => null]);
        }

        try {
            $response = Http::withToken($apiToken)
                ->timeout(5)
                ->post("https://rtc.live.cloudflare.com/v1/turn/keys/{$keyId}/credentials/generate", [
                    'ttl' => 3600,
                ]);

            if (!$response->successful()) {
                return response()->json(['iceServers' => null]);
            }

            return response()->json(['iceServers' => $response->json('iceServers')]);
        } catch (\Throwable $e) {
            return response()->json(['iceServers' => null]);
        }
    }

    public function active(Request $request)
    {
        $user = $request->user();

        $calls = $this->callsForUser($user->id)
            ->whereNull('ended_at')
            ->with(['caller', 'receiver', 'chat.group'])
            ->orderBy('created_at', 'desc')
            ->get();

        $this->appendSavedContactNames($calls, $user);

        return response()->json(['calls' => $calls]);
    }

    public function show(Request $request, $call_id)
    {
        $user = $request->user();

        $call = $this->callsForUser($user->id)
            ->with(['caller', 'receiver', 'chat.group', 'chat.participants.user'])
            ->findOrFail($call_id);

        $this->appendSavedNameToCall($call, $user);

        return response()->json(['call' => $call]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $calls = $this->callsForUser($user->id)
            ->with(['caller', 'receiver', 'chat.group'])
            ->orderBy('created_at', 'desc')
            ->get();

        $this->appendSavedContactNames($calls, $user);
            
        return response()->json(['calls' => $calls]);
    }

    public function initiate(Request $request)
    {
        $request->validate([
            'chat_id' => 'required_without:receiver_id|uuid|exists:chats,id',
            'receiver_id' => 'required_without:chat_id|uuid|exists:users,id',
            'call_type' => 'required|in:audio,video',
        ]);

        $user = $request->user();

        $call = Call::create([
            'caller_id' => $user->id,
            'receiver_id' => $request->receiver_id,
            'chat_id' => $request->chat_id,
            'call_type' => $request->call_type,
            'status' => 'missed', // Default until answered
        ]);

        $call = $call->load('caller', 'receiver', 'chat.group');

        \Illuminate\Support\Facades\Log::info('CallController::initiate', [
            'call_id' => $call->id,
            'caller_id' => $call->caller_id,
            'receiver_id' => $call->receiver_id,
            'chat_id' => $call->chat_id,
            'socket_id' => $request->header('X-Socket-ID'),
        ]);

        // event() (not broadcast()) so SendIncomingCallPushNotification actually
        // runs — broadcast() only sends over the socket and never touches the
        // normal event/listener system, which is why push notifications for
        // calls never fired while the app was closed. dontBroadcastToCurrentUser()
        // reproduces the same "toOthers()" exclusion for the socket broadcast
        // that IncomingCall still performs automatically (it implements
        // ShouldBroadcastNow).
        $incomingCallEvent = new IncomingCall($call);
        $incomingCallEvent->dontBroadcastToCurrentUser();
        event($incomingCallEvent);

        return response()->json(['call' => $call], 201);
    }

    public function accept(Request $request, $call_id)
    {
        $call = $this->callsForUser($request->user()->id)
            ->where(function ($q) use ($request) {
                $q->where('receiver_id', $request->user()->id)
                    ->orWhereHas('chat.participants', function($q2) use ($request) {
                        $q2->where('user_id', $request->user()->id);
                    });
            })
            ->findOrFail($call_id);

        $call->update([
            'status' => 'answered',
            'started_at' => now(),
        ]);

        \Illuminate\Support\Facades\Log::info('CallController::accept', [
            'call_id' => $call->id,
            'accepted_by' => $request->user()->id,
            'notify_caller' => $call->caller_id,
        ]);

        // event() (not broadcast()) so any future listener on this event
        // actually runs — see the identical note on IncomingCall::dispatch
        // above; harmless/no-op today (no listener registered yet) but keeps
        // every call-lifecycle broadcast on the one pattern that's already
        // been proven to matter here.
        $callAnsweredEvent = new CallAnswered($call);
        $callAnsweredEvent->dontBroadcastToCurrentUser();
        event($callAnsweredEvent);

        return response()->json(['status' => 'success']);
    }

    public function decline(Request $request, $call_id)
    {
        $user = $request->user();
        $call = $this->callsForUser($user->id)
            ->where(function ($q) use ($user) {
                $q->where('receiver_id', $user->id)
                    ->orWhereHas('chat.participants', function($q2) use ($user) {
                        $q2->where('user_id', $user->id);
                    });
            })
            ->with('chat')
            ->findOrFail($call_id);

        $isGroup = $call->chat && $call->chat->chat_type === 'group';

        if (!$isGroup) {
            $call->update([
                'status' => 'rejected',
                'ended_at' => now(),
            ]);

            // In a 1-on-1 call, we must notify the other user (the caller) that the call was declined
            $target_id = $call->caller_id === $user->id ? $call->receiver_id : $call->caller_id;
            $callDeclinedEvent = new CallDeclined($call, $target_id);
            $callDeclinedEvent->dontBroadcastToCurrentUser();
            event($callDeclinedEvent);

            $this->createCallMessage($call);
        } else {
            // A decline in a group call just means this one person isn't
            // joining — same signal as leaving an already-active group call
            // (see end() below), so the rest of the mesh cleans up this
            // participant instead of silently never knowing they bailed.
            $this->relaySignal($request, $call_id, [
                'type' => 'client-user-left',
                'userId' => $user->id,
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function end(Request $request, $call_id)
    {
        $user = $request->user();
        $call = $this->callsForUser($user->id)
            ->with('chat')
            ->findOrFail($call_id);

        $isGroup = $call->chat && $call->chat->chat_type === 'group';

        if (!$isGroup) {
            if (!$call->ended_at) {
                $ended_at = now();
                $duration = $call->started_at ? (int) abs($ended_at->diffInSeconds($call->started_at)) : 0;

                $call->update([
                    'ended_at' => $ended_at,
                    'duration_seconds' => $duration,
                ]);

                $this->createCallMessage($call);

                // In a 1-on-1 call, notify the other person to end the call UI
                $target_id = $call->caller_id === $user->id ? $call->receiver_id : $call->caller_id;
                $callDeclinedEvent = new CallDeclined($call, $target_id);
                $callDeclinedEvent->dontBroadcastToCurrentUser();
                event($callDeclinedEvent);
            }
        } else {
            // Previously a no-op: leaving a group call never told anyone
            // else, so the remaining participants only noticed via WebRTC's
            // own ICE-disconnect detection — slow (many seconds) and, before
            // the client-side fix that goes with this, ended the *whole*
            // call screen for them instead of just dropping this one tile.
            // The call's shared row correctly isn't touched here (a group
            // call isn't "over" just because one person left it).
            $this->relaySignal($request, $call_id, [
                'type' => 'client-user-left',
                'userId' => $user->id,
            ]);
        }

        return response()->json(['status' => 'success', 'call' => $call]);
    }
    
    public function clearLogs(Request $request)
    {
        $user = $request->user();
        $this->callsForUser($user->id)->delete();
        return response()->json(['status' => 'success']);
    }

    public function join(Request $request, $call_id)
    {
        $request->validate([
            'target_id' => 'nullable|uuid|exists:users,id',
            'user_name' => 'nullable|string|max:255',
            'user_photo' => 'nullable|string|max:2048',
        ]);

        $user = $request->user();

        $signalData = [
            'type' => 'client-user-joined',
            'userId' => $user->id,
            'userName' => $request->input('user_name', $this->resolveSignalUserName($user)),
            'userPhoto' => $request->input('user_photo', $user->photo_url ?? ''),
        ];

        if ($request->filled('target_id')) {
            $signalData['targetId'] = $request->target_id;
        }

        $this->relaySignal($request, $call_id, $signalData);

        return response()->json(['status' => 'success']);
    }

    public function offer(Request $request, $call_id)
    {
        $request->validate([
            'offer' => 'required|array',
            'target_id' => 'required|uuid|exists:users,id',
            'sender_name' => 'nullable|string|max:255',
            'sender_photo' => 'nullable|string|max:2048',
        ]);

        $user = $request->user();

        $this->relaySignal($request, $call_id, [
            'type' => 'client-webrtc-signal',
            'offer' => $request->offer,
            'targetId' => $request->target_id,
            'senderId' => $user->id,
            'senderName' => $request->input('sender_name', $this->resolveSignalUserName($user)),
            'senderPhoto' => $request->input('sender_photo', $user->photo_url ?? ''),
        ]);

        return response()->json(['status' => 'success']);
    }

    public function answer(Request $request, $call_id)
    {
        $request->validate([
            'answer' => 'required|array',
            'target_id' => 'required|uuid|exists:users,id',
            'sender_name' => 'nullable|string|max:255',
            'sender_photo' => 'nullable|string|max:2048',
        ]);

        $user = $request->user();

        $this->relaySignal($request, $call_id, [
            'type' => 'client-webrtc-signal',
            'answer' => $request->answer,
            'targetId' => $request->target_id,
            'senderId' => $user->id,
            'senderName' => $request->input('sender_name', $this->resolveSignalUserName($user)),
            'senderPhoto' => $request->input('sender_photo', $user->photo_url ?? ''),
        ]);

        return response()->json(['status' => 'success']);
    }

    public function candidate(Request $request, $call_id)
    {
        $request->validate([
            'candidate' => 'required|array',
            'target_id' => 'required|uuid|exists:users,id',
            'sender_name' => 'nullable|string|max:255',
            'sender_photo' => 'nullable|string|max:2048',
        ]);

        $user = $request->user();

        $this->relaySignal($request, $call_id, [
            'type' => 'client-webrtc-signal',
            'candidate' => $request->candidate,
            'targetId' => $request->target_id,
            'senderId' => $user->id,
            'senderName' => $request->input('sender_name', $this->resolveSignalUserName($user)),
            'senderPhoto' => $request->input('sender_photo', $user->photo_url ?? ''),
        ]);

        return response()->json(['status' => 'success']);
    }

    private function createCallMessage(Call $call)
    {
        if ($call->chat_id) {
            $chat = Chat::find($call->chat_id);
        } else {
            $chat = Chat::where('chat_type', 'direct')
                ->whereHas('participants', function ($query) use ($call) {
                    $query->where('user_id', $call->caller_id);
                })
                ->whereHas('participants', function ($query) use ($call) {
                    $query->where('user_id', $call->receiver_id);
                })
                ->first();

            if (!$chat) {
                DB::transaction(function () use (&$chat, $call) {
                    $chat = Chat::create(['chat_type' => 'direct']);
                    $chat->participants()->createMany([
                        ['user_id' => $call->caller_id],
                        ['user_id' => $call->receiver_id],
                    ]);
                });
            }
        }
        
        if (!$chat) return;
        
        $message = $chat->messages()->create([
            'sender_id' => $call->caller_id,
            'message_type' => 'call_log',
            'metadata' => [
                'call_id' => $call->id,
                'call_type' => $call->call_type,
                'status' => $call->status,
                'duration' => $call->duration_seconds ?? 0,
            ],
            'content' => '',
        ]);
        
        $message->load('sender', 'receipts');
        
        $chat->update(['last_message_at' => now()]);
        
        // If it's a missed call, the receiver hasn't seen it
        if ($call->chat_id) {
            $chat->participants()->where('user_id', '!=', $call->caller_id)->increment('unread_count');
        } else {
            $chat->participants()->where('user_id', $call->receiver_id)->increment('unread_count');
        }

        $messageSentEvent = new MessageSent($message);
        $messageSentEvent->dontBroadcastToCurrentUser();
        event($messageSentEvent);
    }

    public function signal(Request $request, $call_id)
    {
        $request->validate([
            'signal_data' => 'required|array'
        ]);

        $this->relaySignal($request, $call_id, $request->signal_data);

        return response()->json(['status' => 'success']);
    }

    private function callsForUser(string $userId)
    {
        // IMPORTANT: the "user is involved in this call" test is three OR'd
        // conditions and MUST be grouped in a closure. Without the closure the
        // ORs are top-level, so when callers chain ->findOrFail($call_id) (or
        // any other ->where), SQL precedence (AND binds tighter than OR) makes
        // the id constraint apply only to the last OR branch:
        //   caller_id=X OR receiver_id=X OR (participant AND id=:id)
        // which matches the *first* call the user is part of, ignoring the id
        // in the URL — so accept/join/offer/etc. operated on the wrong call and
        // the real peer never received the signalling. Grouping gives the
        // correct: (caller_id=X OR receiver_id=X OR participant) AND id=:id.
        return Call::where(function ($query) use ($userId) {
            $query->where('caller_id', $userId)
                ->orWhere('receiver_id', $userId)
                ->orWhereHas('chat.participants', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                });
        });
    }

    private function relaySignal(Request $request, string $callId, array $signalData): void
    {
        $call = $this->callsForUser($request->user()->id)->findOrFail($callId);

        \Illuminate\Support\Facades\Log::info('CallController::relaySignal', [
            'call_id' => $call->id,
            'from' => $request->user()->id,
            'signal_type' => $signalData['type'] ?? null,
            'has_offer' => isset($signalData['offer']),
            'has_answer' => isset($signalData['answer']),
            'has_candidate' => isset($signalData['candidate']),
            'target_id' => $signalData['targetId'] ?? null,
            'socket_id' => $request->header('X-Socket-ID'),
        ]);

        broadcast(new CallSignal($call->id, $signalData))->toOthers();
    }

    private function appendSavedContactNames($calls, $user): void
    {
        $savedContacts = $user->savedContacts->pluck('custom_name', 'contact_user_id');

        foreach ($calls as $call) {
            if ($call->caller) {
                $call->caller->saved_name = $savedContacts[$call->caller->id] ?? null;
            }

            if ($call->receiver) {
                $call->receiver->saved_name = $savedContacts[$call->receiver->id] ?? null;
            }
        }
    }

    private function appendSavedNameToCall(Call $call, $user): void
    {
        $savedContacts = $user->savedContacts->pluck('custom_name', 'contact_user_id');

        if ($call->caller) {
            $call->caller->saved_name = $savedContacts[$call->caller->id] ?? null;
        }

        if ($call->receiver) {
            $call->receiver->saved_name = $savedContacts[$call->receiver->id] ?? null;
        }
    }

    private function resolveSignalUserName($user): string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $user->username ?? ($fullName !== '' ? $fullName : 'User');
    }
}
