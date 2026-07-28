<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;

Route::post('/auth/request-otp', [AuthController::class, 'requestOtp']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/auth/register', [AuthController::class, 'register']);

// Sampay OAuth callback must be publicly reachable by Sampay redirect
Route::get('/sampay/callback', [App\Http\Controllers\Api\SampayController::class, 'callback']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    Route::post('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/online', [App\Http\Controllers\Api\UserController::class, 'heartbeat']);
    Route::post('/user/device-token', [App\Http\Controllers\Api\DeviceTokenController::class, 'store']);
    Route::delete('/user/device-token', [App\Http\Controllers\Api\DeviceTokenController::class, 'destroy']);

    // E2EE key distribution
    Route::post('/device-keys', [App\Http\Controllers\Api\DeviceKeyController::class, 'store']);
    Route::get('/users/{user_id}/device-keys', [App\Http\Controllers\Api\DeviceKeyController::class, 'forUser']);
    Route::post('/chats/{chat_id}/keys', [App\Http\Controllers\Api\ChatKeyController::class, 'store']);
    Route::get('/chats/{chat_id}/keys/mine', [App\Http\Controllers\Api\ChatKeyController::class, 'mine']);
    Route::get('/chats/{chat_id}/keys/missing-devices', [App\Http\Controllers\Api\ChatKeyController::class, 'missingDevices']);
    Route::get('/chats/{chat_id}/keys/exists', [App\Http\Controllers\Api\ChatKeyController::class, 'exists']);
    Route::post('/chats/{chat_id}/keys/request', [App\Http\Controllers\Api\ChatKeyController::class, 'requestGrant']);

    Route::post('/broadcasting/auth', [App\Http\Controllers\Api\BroadcastAuthController::class, 'authenticate']);

    Route::get('/users/search', [App\Http\Controllers\Api\UserController::class, 'search']);

    // Privacy
    Route::get('/users/privacy', [App\Http\Controllers\Api\UserController::class, 'getPrivacy']);
    Route::post('/users/privacy', [App\Http\Controllers\Api\UserController::class, 'updatePrivacy']);
    
    // Address Book / Saved Contacts
    Route::get('/contacts', [App\Http\Controllers\Api\ContactController::class, 'index']);
    Route::post('/contacts', [App\Http\Controllers\Api\ContactController::class, 'store']);
    Route::post('/contacts/sync', [App\Http\Controllers\Api\ContactController::class, 'sync']);
    Route::put('/contacts/{id}', [App\Http\Controllers\Api\ContactController::class, 'update']);
    Route::delete('/contacts/{id}', [App\Http\Controllers\Api\ContactController::class, 'destroy']);
    
    Route::get('/users/blocked', [App\Http\Controllers\Api\BlockController::class, 'index']);
    Route::post('/users/{user_id}/block', [App\Http\Controllers\Api\BlockController::class, 'block']);
    Route::delete('/users/{user_id}/block', [App\Http\Controllers\Api\BlockController::class, 'unblock']);
    Route::get('/users/{user_id}/online-status', [App\Http\Controllers\Api\UserController::class, 'onlineStatus']);
    Route::post('/users/{user_id}/report', [App\Http\Controllers\Api\UserController::class, 'report']);
    Route::get('/users/{user_id}', [App\Http\Controllers\Api\UserController::class, 'show']);
    
    Route::post('/chats', [App\Http\Controllers\Api\ChatController::class, 'store']);
    Route::get('/chats', [App\Http\Controllers\Api\ChatController::class, 'index']);
    Route::get('/chats/{chat_id}', [App\Http\Controllers\Api\ChatController::class, 'show']);
    Route::delete('/chats/{chat_id}', [App\Http\Controllers\Api\ChatController::class, 'destroy']);
    Route::post('/chats/{chat_id}/typing', [App\Http\Controllers\Api\ChatController::class, 'typing']);
    Route::post('/chats/{chat_id}/mute', [App\Http\Controllers\Api\ChatController::class, 'toggleMute']);
    
    Route::post('/chats/{chat_id}/messages', [App\Http\Controllers\Api\ChatController::class, 'storeMessage']);
    Route::delete('/chats/{chat_id}/messages', [App\Http\Controllers\Api\ChatController::class, 'clearMessages']);
    Route::delete('/chats/{chat_id}/messages/bulk', [App\Http\Controllers\Api\ChatController::class, 'bulkDeleteMessages']);
    
    Route::post('/chats/{chat_id}/messages/forward', [App\Http\Controllers\Api\ChatController::class, 'forwardMessage']);
    
    Route::post('/messages/{message_id}/read', [App\Http\Controllers\Api\ChatController::class, 'markAsRead']);
    Route::post('/messages/{message_id}/react', [App\Http\Controllers\Api\ChatController::class, 'reactToMessage']);
    Route::post('/messages/{message_id}/report', [App\Http\Controllers\Api\ChatController::class, 'reportMessage']);
    Route::delete('/messages/{message_id}', [App\Http\Controllers\Api\ChatController::class, 'deleteMessage']);
    
    Route::post('/groups', [App\Http\Controllers\Api\ChatController::class, 'createGroup']);
    Route::put('/chats/{chat_id}/group', [App\Http\Controllers\Api\ChatController::class, 'updateGroup']);
    Route::post('/chats/{chat}/group/image', [App\Http\Controllers\Api\ChatController::class, 'updateGroupImage']);
    Route::post('/chats/{chat_id}/leave', [App\Http\Controllers\Api\ChatController::class, 'leaveGroup']);
    Route::post('/chats/{chat_id}/participants', [App\Http\Controllers\Api\ChatController::class, 'addParticipants']);
    Route::put('/chats/{chat_id}/participants/{user_id}/role', [App\Http\Controllers\Api\ChatController::class, 'updateParticipantRole']);
    Route::delete('/chats/{chat_id}/participants/{user_id}', [App\Http\Controllers\Api\ChatController::class, 'removeParticipant']);
    
    Route::get('/statuses', [App\Http\Controllers\Api\StatusController::class, 'index']);
    Route::post('/statuses', [App\Http\Controllers\Api\StatusController::class, 'store']);
    Route::delete('/statuses/{status_id}', [App\Http\Controllers\Api\StatusController::class, 'destroy']);
    Route::post('/statuses/{status_id}/view', [App\Http\Controllers\Api\StatusController::class, 'markViewed']);
    Route::get('/statuses/{status_id}/views', [App\Http\Controllers\Api\StatusController::class, 'viewers']);
    
    Route::get('/calls', [App\Http\Controllers\Api\CallController::class, 'index']);
    Route::get('/calls/active', [App\Http\Controllers\Api\CallController::class, 'active']);
    Route::get('/calls/turn-credentials', [App\Http\Controllers\Api\CallController::class, 'turnCredentials']);
    Route::post('/calls', [App\Http\Controllers\Api\CallController::class, 'initiate']);
    Route::get('/calls/{call_id}', [App\Http\Controllers\Api\CallController::class, 'show']);
    Route::post('/calls/{call_id}/accept', [App\Http\Controllers\Api\CallController::class, 'accept']);
    Route::post('/calls/{call_id}/decline', [App\Http\Controllers\Api\CallController::class, 'decline']);
    Route::post('/calls/{call_id}/end', [App\Http\Controllers\Api\CallController::class, 'end']);
    Route::post('/calls/{call_id}/join', [App\Http\Controllers\Api\CallController::class, 'join']);
    Route::post('/calls/{call_id}/offer', [App\Http\Controllers\Api\CallController::class, 'offer']);
    Route::post('/calls/{call_id}/answer', [App\Http\Controllers\Api\CallController::class, 'answer']);
    Route::post('/calls/{call_id}/candidate', [App\Http\Controllers\Api\CallController::class, 'candidate']);
    Route::post('/calls/{call_id}/signal', [App\Http\Controllers\Api\CallController::class, 'signal']);
    Route::delete('/calls', [App\Http\Controllers\Api\CallController::class, 'clearLogs']);

    Route::get('/meetings', [App\Http\Controllers\Api\MeetingController::class, 'index']);
    Route::post('/meetings', [App\Http\Controllers\Api\MeetingController::class, 'store']);
    Route::get('/meetings/{meeting_id}', [App\Http\Controllers\Api\MeetingController::class, 'show']);
    Route::post('/meetings/{meeting_id}/respond', [App\Http\Controllers\Api\MeetingController::class, 'respond']);
    Route::post('/meetings/{meeting_id}/start', [App\Http\Controllers\Api\MeetingController::class, 'start']);
    Route::get('/meetings/{meeting_id}/ics', [App\Http\Controllers\Api\MeetingController::class, 'ics']);

    // Email (IMAP/SMTP via app-specific password — Gmail/Yahoo)
    Route::get('/email-accounts', [App\Http\Controllers\Api\EmailAccountController::class, 'index']);
    Route::post('/email-accounts', [App\Http\Controllers\Api\EmailAccountController::class, 'store']);
    Route::post('/email-accounts/{id}/sync', [App\Http\Controllers\Api\EmailAccountController::class, 'sync']);
    Route::delete('/email-accounts/{id}', [App\Http\Controllers\Api\EmailAccountController::class, 'destroy']);
    Route::get('/email-accounts/{accountId}/emails', [App\Http\Controllers\Api\EmailController::class, 'index']);
    Route::post('/email-accounts/{accountId}/send', [App\Http\Controllers\Api\EmailController::class, 'send']);
    Route::get('/emails/{emailId}', [App\Http\Controllers\Api\EmailController::class, 'show']);
    Route::post('/emails/{emailId}/reply', [App\Http\Controllers\Api\EmailController::class, 'reply']);

    // Sampay Integration
    Route::get('/sampay/link', [App\Http\Controllers\Api\SampayController::class, 'link']);
    Route::get('/sampay/status', [App\Http\Controllers\Api\SampayController::class, 'status']);
    Route::delete('/sampay/unlink', [App\Http\Controllers\Api\SampayController::class, 'unlink']);
    Route::post('/chats/{chat_id}/sampay/request', [App\Http\Controllers\Api\SampayController::class, 'requestPayment']);
    Route::post('/chats/{chat_id}/sampay/validate-recipient', [App\Http\Controllers\Api\SampayController::class, 'validateChatPaymentRecipient']);
    Route::post('/chats/{chat_id}/sampay/request-chat', [App\Http\Controllers\Api\SampayController::class, 'requestChatPayment']);
    Route::post('/chats/{chat_id}/sampay/sync-status', [App\Http\Controllers\Api\SampayController::class, 'syncChatPaymentStatuses']);
    Route::post('/chats/{chat_id}/messages/{message_id}/sampay/approve', [App\Http\Controllers\Api\SampayController::class, 'approveChatPayment']);
    Route::post('/chats/{chat_id}/messages/{message_id}/sampay/reject', [App\Http\Controllers\Api\SampayController::class, 'rejectChatPayment']);
});
