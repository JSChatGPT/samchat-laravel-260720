<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\BlockedUser;
use App\Models\DeletedMessage;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = $user->chats()
            ->with(['group', 'participants.user', 'messages' => function ($q) {
                $q->latest()->limit(1);
            }]);

        if ($request->has('filter')) {
            $filter = $request->filter;
            if ($filter === 'unread') {
                $query->wherePivot('unread_count', '>', 0);
            } elseif ($filter === 'groups') {
                $query->where('chat_type', 'group');
            }
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('group', function($subQ) use ($search) {
                    $subQ->where('group_name', 'like', "%{$search}%");
                })
                ->orWhereHas('participants.user', function($subQ) use ($search) {
                    $subQ->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('username', 'like', "%{$search}%");
                });
            });
        }

        $chats = $query->orderByDesc('chats.last_message_at')->get();
            
        $blockedUserIds = \App\Models\BlockedUser::where('blocker_id', $user->id)
            ->orWhere('blocked_id', $user->id)
            ->get()
            ->map(function($b) use ($user) {
                return $b->blocker_id === $user->id ? $b->blocked_id : $b->blocker_id;
            })->values()->toArray();

        $savedContacts = $user->savedContacts->pluck('custom_name', 'contact_user_id');
        
        foreach ($chats as $chat) {
            foreach ($chat->participants as $participant) {
                if ($participant->user) {
                    $participant->user->saved_name = $savedContacts[$participant->user->id] ?? null;
                }
            }
        }

        return response()->json(['chats' => $chats, 'blocked_user_ids' => $blockedUserIds]);
    }
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        $user = $request->user();
        $targetUserId = $request->user_id;

        if ($user->id === $targetUserId) {
            return response()->json(['error' => 'Cannot create a chat with yourself'], 400);
        }

        // Check if a direct chat already exists
        $existingChat = $user->chats()
            ->with('participants.user')
            ->where('chat_type', 'direct')
            ->whereHas('participants', function ($query) use ($targetUserId) {
                $query->where('user_id', $targetUserId);
            })
            ->first();

        $savedContacts = $user->savedContacts->pluck('custom_name', 'contact_user_id');

        if ($existingChat) {
            foreach ($existingChat->participants as $participant) {
                if ($participant->user) {
                    $participant->user->saved_name = $savedContacts[$participant->user->id] ?? null;
                }
            }
            return response()->json(['chat' => $existingChat]);
        }

        // Create new direct chat
        DB::beginTransaction();
        try {
            $chat = Chat::create(['chat_type' => 'direct']);
            
            $chat->participants()->createMany([
                ['user_id' => $user->id],
                ['user_id' => $targetUserId],
            ]);

            DB::commit();
            
            $chat->load('participants.user');
            foreach ($chat->participants as $participant) {
                if ($participant->user) {
                    $participant->user->saved_name = $savedContacts[$participant->user->id] ?? null;
                }
            }
            return response()->json(['chat' => $chat], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create chat'], 500);
        }
    }

    public function show(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = $user->chats()->with(['group', 'participants.user'])->findOrFail($chat_id);
        
        $savedContacts = $user->savedContacts->pluck('custom_name', 'contact_user_id');
        foreach ($chat->participants as $participant) {
            if ($participant->user) {
                $participant->user->saved_name = $savedContacts[$participant->user->id] ?? null;
            }
        }
        
        // Reset unread count
        $user->chats()->updateExistingPivot($chat_id, ['unread_count' => 0]);

        // Find unread messages from others
        $unreadMessages = $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereDoesntHave('receipts', function($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'read');
            })->get();

        if ($unreadMessages->count() > 0) {
            $now = now();
            $receiptsData = [];
            $readMessageIds = [];
            foreach ($unreadMessages as $msg) {
                $receiptsData[] = [
                    'message_id' => $msg->id,
                    'user_id' => $user->id,
                    'status' => 'read',
                    'created_at' => $now,
                ];
                $readMessageIds[] = $msg->id;
            }
            \App\Models\MessageReceipt::insert($receiptsData);

            // Broadcast MessagesRead event
            broadcast(new \App\Events\MessagesRead($chat->id, $readMessageIds))->toOthers();
        }
        
        // Block check for direct chats
        $isBlocked = false;
        if ($chat->chat_type === 'direct') {
            $otherUserId = $chat->participants->where('user_id', '!=', $user->id)->first()->user_id ?? null;
            if ($otherUserId) {
                $isBlocked = BlockedUser::where(function($q) use ($user, $otherUserId) {
                    $q->where('blocker_id', $user->id)->where('blocked_id', $otherUserId);
                })->orWhere(function($q) use ($user, $otherUserId) {
                    $q->where('blocker_id', $otherUserId)->where('blocked_id', $user->id);
                })->exists();
                
                $chat->is_blocked = $isBlocked; // Append to chat object
                $chat->blocked_by_me = BlockedUser::where('blocker_id', $user->id)->where('blocked_id', $otherUserId)->exists();
            }
        }
        
        $deletedMessageIds = DeletedMessage::where('user_id', $user->id)->pluck('message_id');

        $messages = $chat->messages()
            ->whereNotIn('id', $deletedMessageIds)
            ->with(['sender', 'receipts', 'quotedMessage.sender', 'reactions'])
            ->latest() // equivalent to orderBy('created_at', 'desc')
            ->paginate(50);
            
        return response()->json(['chat' => $chat, 'messages' => $messages]);
    }

    public function storeMessage(Request $request, $chat_id)
    {
        $request->validate([
            'message_type' => 'required|string',
            'content' => 'nullable|string',
            'attachment' => 'nullable|file|max:10240', // Max 10MB
            // This endpoint accepts a file upload, so it's submitted as
            // multipart/form-data whenever there's an attachment (and the
            // mobile client always uses multipart, attachment or not) —
            // multipart can't carry a nested object natively, so metadata
            // arrives as a JSON-encoded string in that case (a real nested
            // array only when the caller sent a plain JSON body instead, as
            // the web client does). Accept either shape here; normalized to
            // a real array below.
            'metadata' => 'nullable',
            'quoted_message_id' => 'nullable|uuid|exists:messages,id',
        ]);

        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);
        
        // Block check
        if ($chat->chat_type === 'direct') {
            $otherUserId = $chat->participants()->where('user_id', '!=', $user->id)->first()->user_id ?? null;
            if ($otherUserId) {
                $isBlocked = BlockedUser::where(function($q) use ($user, $otherUserId) {
                    $q->where('blocker_id', $user->id)->where('blocked_id', $otherUserId);
                })->orWhere(function($q) use ($user, $otherUserId) {
                    $q->where('blocker_id', $otherUserId)->where('blocked_id', $user->id);
                })->exists();

                if ($isBlocked) {
                    return response()->json(['error' => 'Cannot send messages to a blocked chat.'], 403);
                }
            }
        }

        $metadata = [];
        if (is_array($request->metadata)) {
            $metadata = $request->metadata;
        } elseif (is_string($request->metadata) && $request->metadata !== '') {
            $decoded = json_decode($request->metadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }
        $content = $request->content ?? '';

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('uploads', 'public');
            
            $metadata['media_url'] = '/storage/' . $path;
            $metadata['file_name'] = $file->getClientOriginalName();
            $metadata['mime_type'] = $file->getClientMimeType();
        }

        DB::beginTransaction();
        try {
            $message = $chat->messages()->create([
                'sender_id' => $user->id,
                'message_type' => $request->message_type,
                'content' => $content,
                // Message::$casts already declares 'metadata' => 'array', which
                // encodes on write/decodes on read by itself — encoding it here
                // too meant a *string* got json_encode()'d a second time by the
                // cast, so metadata came back from every read as a JSON string
                // instead of a nested object (confirmed via a real row:
                // metadata ended up as the PHP string '{"encrypted":true}'
                // rather than ['encrypted' => true]). That broke every
                // consumer that checks `metadata['encrypted'] === true` before
                // deciding whether to decrypt — including this app's own
                // Flutter client, which silently rendered ciphertext as if it
                // were plaintext instead of decrypting or showing a
                // placeholder.
                'metadata' => $metadata,
                'quoted_message_id' => $request->quoted_message_id,
            ]);

            $chat->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ]);
            
            // Increment unread count for other participants
            $chat->participants()->where('user_id', '!=', $user->id)->increment('unread_count');
            
            
            DB::commit();

            $message->load(['quotedMessage.sender']);

            // WebSockets Broadcast logic
            $messageSentEvent = new \App\Events\MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json(['message' => $message], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to send message'], 500);
        }
    }

    public function markAsRead(Request $request, $message_id)
    {
        $user = $request->user();
        $message = Message::findOrFail($message_id);

        // Ensure the caller is actually a participant of the message's chat
        $user->chats()->findOrFail($message->chat_id);

        MessageReceipt::firstOrCreate([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'status' => 'read',
        ]);

        broadcast(new \App\Events\MessagesRead($message->chat_id, [$message->id]))->toOthers();

        return response()->json(['status' => 'success']);
    }

    public function reactToMessage(Request $request, $message_id)
    {
        $request->validate([
            'emoji' => 'required|string',
        ]);

        $user = $request->user();
        $message = Message::findOrFail($message_id);

        // Ensure the caller is actually a participant of the message's chat
        $user->chats()->findOrFail($message->chat_id);

        $existing = \App\Models\MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->emoji === $request->emoji) {
            $existing->delete();
            $action = 'removed';
        } elseif ($existing) {
            $existing->update(['emoji' => $request->emoji]);
            $action = 'added';
        } else {
            \App\Models\MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $request->emoji,
            ]);
            $action = 'added';
        }

        $message->load('reactions');

        broadcast(new \App\Events\MessageReactionUpdated($message, $user, $request->emoji, $action))->toOthers();

        return response()->json(['reactions' => $message->reactions]);
    }

    public function clearMessages(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        $chat->messages()->delete();

        return response()->json(['status' => 'success', 'message' => 'Chat cleared']);
    }

    public function destroy(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);
        
        $chat->messages()->delete();
        // Since chat is shared, deleting it completely might delete for other participants. 
        // For a full WhatsApp clone, a chat deletion usually means deleting messages and the participant record.
        // If it's a group, they leave the group.
        $chat->participants()->where('user_id', $user->id)->delete();
        
        // If no participants left, delete the chat
        if ($chat->participants()->count() === 0) {
            $chat->delete();
        }

        return response()->json(['status' => 'success']);
    }

    public function bulkDeleteMessages(Request $request, $chat_id)
    {
        $request->validate([
            'message_ids' => 'required|array',
            'type' => 'required|in:me,everyone'
        ]);

        $user = $request->user();
        $type = $request->type;
        $messageIds = $request->message_ids;

        if ($type === 'everyone') {
            // Can only delete for everyone if user is sender
            Message::whereIn('id', $messageIds)
                ->where('sender_id', $user->id)
                ->delete();
        } else {
            $inserts = [];
            foreach ($messageIds as $id) {
                $inserts[] = [
                    'message_id' => $id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            // Use insertOrIgnore if supported, else just insert
            try {
                DeletedMessage::insert($inserts);
            } catch (\Exception $e) {
                // Ignore duplicates
            }
        }

        return response()->json(['status' => 'success']);
    }

    public function deleteMessage(Request $request, $message_id)
    {
        $user = $request->user();
        $message = Message::findOrFail($message_id);
        $type = $request->query('type', 'me'); // default to me

        if ($type === 'everyone') {
            // Only sender can delete for everyone
            if ($message->sender_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            $message->delete();
        } else {
            // Delete for me
            DeletedMessage::firstOrCreate([
                'message_id' => $message->id,
                'user_id' => $user->id
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function createGroup(Request $request)
    {
        $request->validate([
            'group_name' => 'required|string|max:255',
            'user_ids' => 'required|array',
            'user_ids.*' => 'uuid|exists:users,id',
        ]);

        $user = $request->user();
        $userIds = $request->user_ids;
        if (!in_array($user->id, $userIds)) {
            $userIds[] = $user->id; // Ensure creator is in the group
        }

        DB::beginTransaction();
        try {
            $chat = Chat::create(['chat_type' => 'group']);
            
            $chat->group()->create([
                'group_name' => $request->group_name,
                'created_by_user_id' => $user->id,
            ]);

            $participants = [];
            foreach ($userIds as $uid) {
                $participants[] = ['user_id' => $uid, 'is_admin' => ($uid === $user->id)];
            }
            $chat->participants()->createMany($participants);

            DB::commit();
            return response()->json(['chat' => $chat->load('group', 'participants.user')], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create group'], 500);
        }
    }

    public function updateGroupImage(Request $request, Chat $chat)
    {
        $user = $request->user();
        
        if ($chat->chat_type !== 'group' || !$chat->group) {
            return response()->json(['message' => 'Not a group chat.'], 400);
        }

        if ($chat->group->created_by_user_id !== $user->id) {
            return response()->json(['message' => 'Only the group admin can update the image.'], 403);
        }

        $request->validate([
            'group_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        $path = $request->file('group_image')->store('group_photos', 'public');
        
        $chat->group->update([
            'group_image' => $path
        ]);

        return response()->json([
            'message' => 'Group image updated successfully.',
            'group_image_url' => asset('storage/' . $path)
        ]);
    }

    public function leaveGroup(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = Chat::where('id', $chat_id)->where('chat_type', 'group')->firstOrFail();

        $chat->participants()->where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Left group successfully.']);
    }

    public function addParticipants(Request $request, $chat_id)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'uuid|exists:users,id',
        ]);

        $user = $request->user();
        $chat = Chat::where('id', $chat_id)->where('chat_type', 'group')->firstOrFail();

        $isAdmin = $chat->participants()->where('user_id', $user->id)->where('is_admin', true)->exists();
        if (!$isAdmin) {
            return response()->json(['message' => 'Only admins can add participants.'], 403);
        }

        $existingUserIds = $chat->participants()->pluck('user_id')->toArray();
        $newUserIds = array_diff($request->user_ids, $existingUserIds);

        $participants = [];
        foreach ($newUserIds as $uid) {
            $participants[] = ['user_id' => $uid, 'is_admin' => false];
        }
        
        if (!empty($participants)) {
            $chat->participants()->createMany($participants);
        }

        return response()->json(['chat' => $chat->load('group', 'participants.user')]);
    }

    public function updateParticipantRole(Request $request, $chat_id, $user_id)
    {
        $request->validate([
            'is_admin' => 'required|boolean'
        ]);

        $user = $request->user();
        $chat = Chat::where('id', $chat_id)->where('chat_type', 'group')->firstOrFail();

        $isAdmin = $chat->participants()->where('user_id', $user->id)->where('is_admin', true)->exists();
        if (!$isAdmin) {
            return response()->json(['message' => 'Only admins can manage roles.'], 403);
        }

        $chat->participants()->where('user_id', $user_id)->update(['is_admin' => $request->is_admin]);

        return response()->json(['chat' => $chat->load('group', 'participants.user')]);
    }

    public function removeParticipant(Request $request, $chat_id, $user_id)
    {
        $user = $request->user();
        $chat = Chat::where('id', $chat_id)->where('chat_type', 'group')->firstOrFail();

        $isAdmin = $chat->participants()->where('user_id', $user->id)->where('is_admin', true)->exists();
        if (!$isAdmin) {
            return response()->json(['message' => 'Only admins can remove participants.'], 403);
        }

        if ($user->id === $user_id) {
            return response()->json(['message' => 'Use leave group instead.'], 400);
        }

        $chat->participants()->where('user_id', $user_id)->delete();

        return response()->json(['chat' => $chat->load('group', 'participants.user')]);
    }

    public function forwardMessage(Request $request, $chat_id)
    {
        $request->validate([
            'message_id' => 'required|uuid|exists:messages,id'
        ]);

        $user = $request->user();
        $targetChat = $user->chats()->findOrFail($chat_id);
        
        $originalMessage = Message::findOrFail($request->message_id);

        DB::beginTransaction();
        try {
            $message = $targetChat->messages()->create([
                'sender_id' => $user->id,
                'message_type' => $originalMessage->message_type,
                'content' => $originalMessage->content,
                // $originalMessage->metadata is already a decoded array
                // (Message::$casts) — re-encoding it here double-encodes,
                // same bug as storeMessage() above.
                'metadata' => $originalMessage->metadata ?: null,
                'is_forwarded' => true,
            ]);

            $targetChat->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ]);
            
            $targetChat->participants()->where('user_id', '!=', $user->id)->increment('unread_count');
            
            DB::commit();

            $messageSentEvent = new \App\Events\MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json(['message' => $message], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to forward message'], 500);
        }
    }

    public function updateGroup(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = Chat::where('id', $chat_id)->where('chat_type', 'group')->firstOrFail();

        $isAdmin = $chat->participants()->where('user_id', $user->id)->where('is_admin', true)->exists();
        if (!$isAdmin) {
            return response()->json(['message' => 'Only admins can update group settings.'], 403);
        }

        $request->validate([
            'group_name' => 'sometimes|string|max:255',
            'only_admins_can_post' => 'sometimes|boolean',
        ]);

        $updateData = $request->only(['group_name', 'only_admins_can_post']);
        
        if (!empty($updateData)) {
            $chat->group->update($updateData);
        }

        return response()->json(['chat' => $chat->load('group', 'participants.user')]);
    }

    public function typing(Request $request, $chat_id)
    {
        $request->validate([
            'is_typing' => 'required|boolean'
        ]);

        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        broadcast(new \App\Events\UserTyping($chat->id, $user->id, $request->is_typing))->toOthers();

        return response()->json(['status' => 'success']);
    }

    public function toggleMute(Request $request, $chat_id)
    {
        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        $participant = $chat->participants()->where('user_id', $user->id)->first();
        if ($participant) {
            $participant->update(['is_muted' => !$participant->is_muted]);
        }

        return response()->json(['status' => 'success', 'is_muted' => $participant->is_muted]);
    }
}
