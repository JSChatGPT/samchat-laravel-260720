<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Chat;
use Illuminate\Support\Facades\DB;
use App\Support\PhoneNumber;
use App\Models\BlockedUser;

class ExternalMessageController extends Controller
{
    /**
     * Send a message to a user by phone number (WhatsApp Cloud API style)
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'to' => 'required|string',
            'type' => 'required|string|in:text', // extensible for images later
            'text.body' => 'required_if:type,text|string',
        ]);

        $sender = $request->user();
        $rawTo = $request->input('to');
        
        // Normalize the recipient phone number to E.164
        $toPhone = PhoneNumber::toE164($rawTo);
        
        $recipient = User::where('phone_number', $toPhone)->first();
        
        if (!$recipient) {
            return response()->json([
                'error' => [
                    'message' => 'Recipient phone number not registered on SamChats.',
                    'type' => 'invalid_request_error',
                    'code' => 400
                ]
            ], 400);
        }

        if ($sender->id === $recipient->id) {
            return response()->json([
                'error' => [
                    'message' => 'Cannot send message to yourself.',
                    'code' => 400
                ]
            ], 400);
        }

        // Find or create direct chat
        $chat = $sender->chats()
            ->where('chat_type', 'direct')
            ->whereHas('participants', function ($query) use ($recipient) {
                $query->where('user_id', $recipient->id);
            })
            ->first();

        DB::beginTransaction();
        try {
            if (!$chat) {
                $chat = Chat::create(['chat_type' => 'direct']);
                $chat->participants()->createMany([
                    ['user_id' => $sender->id],
                    ['user_id' => $recipient->id],
                ]);
            }

            // Check if blocked
            $isBlocked = BlockedUser::where(function($q) use ($sender, $recipient) {
                $q->where('blocker_id', $sender->id)->where('blocked_id', $recipient->id);
            })->orWhere(function($q) use ($sender, $recipient) {
                $q->where('blocker_id', $recipient->id)->where('blocked_id', $sender->id);
            })->exists();

            if ($isBlocked) {
                return response()->json([
                    'error' => [
                        'message' => 'Cannot send messages to a blocked chat.',
                        'code' => 403
                    ]
                ], 403);
            }

            $content = $request->input('text.body');
            
            $message = $chat->messages()->create([
                'sender_id' => $sender->id,
                'message_type' => 'text',
                'content' => $content,
                'metadata' => null,
            ]);

            $chat->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ]);
            
            // Increment unread count for recipient
            $chat->participants()->where('user_id', $recipient->id)->increment('unread_count');
            
            DB::commit();

            $message->load(['quotedMessage.sender', 'reactions', 'receipts']);

            // WebSockets Broadcast logic
            $messageSentEvent = new \App\Events\MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json([
                'messaging_product' => 'samchats',
                'contacts' => [
                    ['input' => $rawTo, 'wa_id' => str_replace('+', '', $toPhone)]
                ],
                'messages' => [
                    ['id' => $message->id]
                ]
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => [
                    'message' => 'Internal server error while sending message.',
                    'code' => 500,
                    'details' => $e->getMessage()
                ]
            ], 500);
        }
    }
}
