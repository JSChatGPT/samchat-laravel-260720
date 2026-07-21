<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Events\ChatKeyGrantRequested;
use App\Models\ChatKeyGrant;
use App\Models\UserDeviceKey;

class ChatKeyController extends Controller
{
    /**
     * Bulk-upload the chat's symmetric key, individually sealed to every
     * current participant's device public key(s) — called by the client
     * right after creating a chat/group, or after adding a participant.
     * The server only ever stores/relays these opaque blobs.
     */
    public function store(Request $request, $chat_id)
    {
        $request->validate([
            'grants' => 'required|array|min:1',
            'grants.*.user_id' => 'required|uuid|exists:users,id',
            'grants.*.device_id' => 'required|string',
            'grants.*.sealed_key' => 'required|string',
        ]);

        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        // Whether this chat already has an established key BEFORE this
        // request's grants are applied. A device whose local identity
        // resets (reinstall — a brand new device_id/keypair, indistin­
        // guishable server-side from a genuinely new device) has no way to
        // know the chat is already encrypted, and would otherwise generate
        // and upload a brand-new key here purely because *it* lacks one —
        // silently overwriting every other participant's working grant via
        // updateOrCreate and permanently orphaning every message already
        // encrypted under the real key. Once a chat is keyed, only ever add
        // grants for devices that don't have one yet; never touch an
        // existing sealed_key.
        $alreadyKeyed = ChatKeyGrant::where('chat_id', $chat->id)->exists();

        foreach ($request->grants as $grant) {
            $attributes = ['chat_id' => $chat->id, 'user_id' => $grant['user_id'], 'device_id' => $grant['device_id']];
            if ($alreadyKeyed) {
                ChatKeyGrant::firstOrCreate($attributes, ['sealed_key' => $grant['sealed_key']]);
            } else {
                ChatKeyGrant::updateOrCreate($attributes, ['sealed_key' => $grant['sealed_key']]);
            }
        }

        return response()->json(['status' => 'success'], 201);
    }

    /**
     * Whether this chat already has a key established by ANY participant
     * device — the client checks this before generating a new one (see
     * E2eeService.distributeNewChatKey) so a device that simply lacks its
     * own grant yet (freshly reset identity, or a genuinely new chat it
     * hasn't caught up on) doesn't mistake "I don't have a key" for "no key
     * exists" and mint a competing one.
     */
    public function exists(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        return response()->json(['exists' => ChatKeyGrant::where('chat_id', $chat->id)->exists()]);
    }

    /**
     * This device's own sealed key for a chat, so it can unseal it locally
     * and start encrypting/decrypting messages in that chat.
     */
    public function mine(Request $request, $chat_id)
    {
        $request->validate(['device_id' => 'required|string']);

        $user = $request->user();
        $user->chats()->findOrFail($chat_id);

        $grant = ChatKeyGrant::where('chat_id', $chat_id)
            ->where('user_id', $user->id)
            ->where('device_id', $request->device_id)
            ->first();

        if (!$grant) {
            return response()->json(['sealed_key' => null], 404);
        }

        return response()->json(['sealed_key' => $grant->sealed_key]);
    }

    /**
     * Every registered device, across every current participant, that does
     * NOT yet hold a sealed copy of this chat's key — self-healing key
     * distribution. A device only ever gets a grant at the moment the chat
     * key is created/it's added as a participant/it's added as a new
     * device; nothing re-checks this afterwards, so a device whose local
     * identity resets (e.g. reinstall — a genuinely new device_id/keypair,
     * indistinguishable server-side from a brand new device) permanently
     * loses access to a chat's history unless something notices and reseals
     * to it. Called opportunistically by any device that still holds the
     * key (see E2eeService.tryEncrypt on the client) right before it sends
     * a message — cheap (a handful of rows at most) and self-correcting:
     * the very next message sent by anyone who still has access repairs
     * every other participant's stale/missing devices.
     */
    public function missingDevices(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = $user->chats()->with('participants')->findOrFail($chat_id);

        $participantIds = $chat->participants->pluck('user_id');

        // Keyed by "user_id:device_id", not device_id alone — device_id is
        // only a client-generated UUID, not guaranteed unique across users
        // by anything the server enforces, so matching on it alone could
        // wrongly treat one user's device as already granted because some
        // other user's device_id happened to appear in the grants table.
        $grantedKeys = ChatKeyGrant::where('chat_id', $chat_id)
            ->get(['user_id', 'device_id'])
            ->map(fn ($g) => $g->user_id . ':' . $g->device_id)
            ->all();

        $missing = UserDeviceKey::whereIn('user_id', $participantIds)
            ->get(['user_id', 'device_id', 'public_key'])
            ->reject(fn ($d) => in_array($d->user_id . ':' . $d->device_id, $grantedKeys, true))
            ->values();

        return response()->json(['devices' => $missing]);
    }

    /**
     * A device that just registered but has no grant yet for an
     * already-encrypted chat asks every other currently-connected device
     * (across every participant, including this user's OTHER devices — the
     * common case is this very user switching phones or logging into web)
     * to reseal the key to it right now, instead of waiting for the next
     * opportunistic heal-on-send (see E2eeService.ensureChatKeyAvailable /
     * handleGrantRequest, and the missingDevices doc comment above).
     */
    public function requestGrant(Request $request, $chat_id)
    {
        $user = $request->user();
        $user->chats()->findOrFail($chat_id);

        event(new ChatKeyGrantRequested($chat_id, $user->id));

        return response()->json(['status' => 'requested']);
    }
}
