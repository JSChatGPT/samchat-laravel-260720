<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('call.{call_id}', function ($user, $call_id) {
    $call = \App\Models\Call::find($call_id);
    if (!$call) return false;
    
    if ($call->chat_id) {
        return $user->chats()->where('chats.id', $call->chat_id)->exists();
    }
    
    return $user->id === $call->caller_id || $user->id === $call->receiver_id;
});

Broadcast::channel('chat.{chat_id}', function ($user, $chat_id) {
    return $user->chats()->where('chat_id', $chat_id)->exists();
});

Broadcast::channel('app', function ($user) {
    return ['id' => $user->id, 'username' => $user->username];
});

