<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\SampayAccount;
use App\Models\Chat;
use App\Models\User;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Events\MessageSent;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class SampayController extends Controller
{
    private function resolveChatPaymentTarget(Request $request, Chat $chat)
    {
        if ($chat->chat_type === 'direct') {
            return $chat->participants->firstWhere('user_id', '!=', $request->user()->id);
        }

        $recipientUserId = $request->input('recipient_user_id');
        if (!$recipientUserId) {
            return null;
        }

        return $chat->participants->firstWhere('user_id', $recipientUserId);
    }

    private function isValidationSuccessful(array $payload): bool
    {
        if (array_key_exists('status', $payload)) {
            return filter_var($payload['status'], FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    private function extractValidationData(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    private function getBaseUrl()
    {
        return rtrim((string) config('services.sampay.base_url', env('SAMPAY_BASE_URL')), '/');
    }

    private function isBaseUrlConfigured(): bool
    {
        $url = $this->getBaseUrl();
        if ($url === '') {
            return false;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return false;
        }

        return in_array(($parsed['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parsed['host']);
    }

    private function linkedSampayAccountFor(?User $user): ?SampayAccount
    {
        if (!$user) {
            return null;
        }

        $account = $user->sampayAccount;
        if (!$account) {
            return null;
        }

        if (empty((string) $account->access_token)) {
            return null;
        }

        return $account;
    }

    private function wasRecentlyChecked(array $metadata, int $seconds = 7): bool
    {
        $checkedAt = $metadata['status_checked_at'] ?? null;
        if (!is_string($checkedAt) || $checkedAt === '') {
            return false;
        }

        try {
            return Carbon::parse($checkedAt)->greaterThan(now()->subSeconds($seconds));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getClientId()
    {
        return (string) config('services.sampay.client_id', env('SAMPAY_CLIENT_ID'));
    }

    private function getClientSecret()
    {
        return (string) config('services.sampay.client_secret', env('SAMPAY_CLIENT_SECRET'));
    }

    private function getRedirectUri()
    {
        $configured = config('services.sampay.redirect_uri', env('SAMPAY_REDIRECT_URI'));
        if (!empty($configured)) {
            return (string) $configured;
        }

        return rtrim((string) env('APP_URL'), '/') . '/api/sampay/callback';
    }

    public function link(Request $request)
    {
        if (!$this->isBaseUrlConfigured() || !$this->getClientId() || !$this->getClientSecret() || !$this->getRedirectUri()) {
            return response()->json(['error' => 'Sampay credentials are not configured.'], 500);
        }

        $state = Str::random(64);
        Cache::put("sampay_oauth_state:{$state}", $request->user()->id, now()->addMinutes(10));

        $authUrl = rtrim($this->getBaseUrl(), '/') . '/oauth/authorize?' . http_build_query([
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
        ]);

        return response()->json([
            'authorization_url' => $authUrl,
        ]);
    }

    public function callback(Request $request)
    {
        if (!$this->isBaseUrlConfigured() || !$this->getClientId() || !$this->getClientSecret() || !$this->getRedirectUri()) {
            return redirect('/app?sampay_error=credentials_not_configured');
        }

        if ($request->has('error')) {
            return redirect('/app?sampay_error=' . urlencode((string) $request->query('error')));
        }

        if (!$request->filled('code') || !$request->filled('state')) {
            return redirect('/app?sampay_error=missing_code_or_state');
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $userId = Cache::pull('sampay_oauth_state:' . $request->state);
        if (!$userId) {
            return redirect('/app?sampay_error=invalid_or_expired_state');
        }

        $user = User::find($userId);
        if (!$user) {
            return redirect('/app?sampay_error=user_not_found');
        }

        // 1. Exchange code for access token
        $response = Http::asForm()->post($this->getBaseUrl() . '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri' => $this->getRedirectUri(),
            'code' => $request->code,
        ]);

        if ($response->failed()) {
            $tokenError = $response->json('error');
            $tokenDescription = $response->json('error_description');

            Log::warning('Sampay OAuth token exchange failed', [
                'status' => $response->status(),
                'oauth_error' => $tokenError,
                'oauth_error_description' => $tokenDescription,
                'redirect_uri_used' => $this->getRedirectUri(),
                'client_id_used' => $this->getClientId(),
            ]);

            if ($tokenError === 'invalid_client') {
                return redirect('/app?sampay_error=invalid_client_check_client_id_secret_redirect_uri');
            }

            return redirect('/app?sampay_error=token_exchange_failed');
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'] ?? null;
        if (!$accessToken) {
            return redirect('/app?sampay_error=missing_access_token');
        }

        // 2. Fetch user profile from Sampay
        $userResponse = Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->getBaseUrl() . '/api/v1/user');

        if ($userResponse->failed()) {
            Log::warning('Sampay profile fetch failed', [
                'status' => $userResponse->status(),
                'body' => $userResponse->json() ?: $userResponse->body(),
            ]);

            return redirect('/app?sampay_error=profile_fetch_failed');
        }

        $sampayUser = $userResponse->json();

        // 3. Save to database
        $sampayAccount = SampayAccount::updateOrCreate(
            ['user_id' => $user->id],
            [
                'sampay_id' => $sampayUser['id'] ?? null,
                'username' => $sampayUser['username'] ?? null,
                'mobile_number' => $sampayUser['mobile_number'] ?? null,
                'access_token' => $accessToken,
            ]
        );

        return redirect('/app?sampay_linked=1');
    }

    public function status(Request $request)
    {
        $sampayAccount = $request->user()->sampayAccount;

        if (!$sampayAccount) {
            return response()->json([
                'is_linked' => false,
            ]);
        }

        return response()->json([
            'is_linked' => true,
            'sampay_account' => [
                'username' => $sampayAccount->username,
                'mobile_number' => $sampayAccount->mobile_number,
            ]
        ]);
    }

    public function unlink(Request $request)
    {
        $user = $request->user();
        
        if ($user->sampayAccount) {
            $user->sampayAccount->delete();
        }

        return response()->json(['message' => 'Sampay account unlinked successfully.']);
    }

    public function requestPayment(Request $request, $chat_id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:255',
            'recipient_user_id' => 'required|uuid|exists:users,id',
        ]);

        if (!$this->isBaseUrlConfigured()) {
            return response()->json(['error' => 'Sampay service is not configured. Please contact support.'], 503);
        }

        $user = $request->user();
        
        // Ensure sender has linked Sampay
        $senderSampay = $this->linkedSampayAccountFor($user);
        if (!$senderSampay) {
            return response()->json(['error' => 'You must link (or relink) your Sampay account first.'], 400);
        }

        $chat = $user->chats()->findOrFail($chat_id);

        // Ensure recipient is in the chat
        $isRecipientInChat = $chat->participants()->where('user_id', $request->recipient_user_id)->exists();
        if (!$isRecipientInChat) {
            return response()->json(['error' => 'Recipient is not a participant in this chat.'], 400);
        }

        // Get recipient's Sampay account
        $recipientSampay = SampayAccount::where('user_id', $request->recipient_user_id)->first();
        if (!$recipientSampay || (!$recipientSampay->username && !$recipientSampay->mobile_number)) {
            return response()->json(['error' => 'Recipient has not linked a valid Sampay account.'], 400);
        }

        $recipientAccount = $recipientSampay->username ?: $recipientSampay->mobile_number;
        $recipientUsername = $recipientSampay->username;

        if (!$recipientUsername) {
            return response()->json(['error' => 'Recipient must have a Sampay username for recipient validation.'], 400);
        }

        $purpose = $request->description ?: 'Chat payment request';
        $remarks = $request->description;

        $validateResponse = Http::withToken($senderSampay->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->post($this->getBaseUrl() . '/api/v1/external-payments/validate-recipient', [
                'recipient_username' => $recipientUsername,
                'amount' => $request->amount,
                'purpose' => $purpose,
                'remarks' => $remarks,
            ]);

        if ($validateResponse->failed()) {
            Log::warning('Legacy requestPayment recipient validation failed', [
                'status' => $validateResponse->status(),
                'payload' => $validateResponse->json(),
            ]);
            return response()->json([
                'error' => 'Recipient validation failed.',
                'details' => $validateResponse->json()
            ], 400);
        }

        $validatePayload = $validateResponse->json() ?: [];
        if (!$this->isValidationSuccessful($validatePayload)) {
            Log::warning('Legacy requestPayment recipient validation rejected', [
                'payload' => $validatePayload,
            ]);
            return response()->json([
                'error' => 'Recipient validation was not approved by Sampay.',
                'details' => $validatePayload,
            ], 400);
        }

        // 1. Call Sampay API to request payment
        $response = Http::withToken($senderSampay->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->post($this->getBaseUrl() . '/api/v1/external-payments/request', [
                'recipient_username' => $recipientUsername,
                'recipient_account' => $recipientAccount,
                'amount' => $request->amount,
                'purpose' => $purpose,
                'remarks' => $remarks,
                'description' => $request->description,
            ]);

        if ($response->failed()) {
            Log::warning('Legacy requestPayment external request failed', [
                'status' => $response->status(),
                'payload' => $response->json(),
            ]);
            return response()->json([
                'error' => 'Failed to create payment request on Sampay.',
                'details' => $response->json()
            ], 400);
        }

        $paymentData = $response->json();

        // 2. Insert chat message
        $metadata = [
            'amount' => $request->amount,
            'description' => $request->description,
            'sampay_request_id' => $paymentData['request']['id'] ?? null,
            'status' => 'pending'
        ];

        DB::beginTransaction();
        try {
            $message = $chat->messages()->create([
                'sender_id' => $user->id,
                'message_type' => 'payment_request',
                'content' => 'Payment request for ZMW ' . $request->amount,
                // Message::$casts already declares 'metadata' => 'array' —
                // it encodes on write by itself, so json_encode()'ing here
                // too double-encoded it (same bug fixed in
                // ChatController::storeMessage).
                'metadata' => $metadata,
            ]);

            $chat->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ]);
            
            $chat->participants()->where('user_id', '!=', $user->id)->increment('unread_count');
            
            DB::commit();

            // Broadcast message
            $messageSentEvent = new MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json([
                'message' => 'Payment request sent successfully.',
                'chat_message' => $message,
                'sampay_response' => $paymentData
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Payment requested but failed to create chat message.'], 500);
        }
    }

    public function validateChatPaymentRecipient(Request $request, $chat_id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'recipient_type' => 'required|string|in:personal,business',
            'recipient_account' => 'required|string|max:255',
            'purpose' => 'required|string|max:120',
            'remarks' => 'nullable|string|max:255',
            'recipient_user_id' => 'nullable|uuid',
        ]);

        if (!$this->isBaseUrlConfigured()) {
            return response()->json(['error' => 'Sampay service is not configured. Please contact support.'], 503);
        }

        $user = $request->user();
        $chat = $user->chats()->with('participants')->findOrFail($chat_id);

        if ($chat->chat_type === 'group' && !$request->filled('recipient_user_id')) {
            return response()->json(['error' => 'Select a group member to pay.'], 422);
        }

        $senderSampay = $this->linkedSampayAccountFor($user);
        if (!$senderSampay) {
            return response()->json(['error' => 'You must link (or relink) your Sampay account first.'], 400);
        }

        $targetParticipant = $this->resolveChatPaymentTarget($request, $chat);
        if (!$targetParticipant) {
            return response()->json(['error' => 'Unable to determine target user for this chat.'], 400);
        }

        $validationResponse = Http::withToken($senderSampay->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->post($this->getBaseUrl() . '/api/v1/external-payments/validate-recipient', [
                'recipient_type' => $request->recipient_type,
                'accounttype' => $request->recipient_type,
                'recipient_account' => $request->recipient_account,
                'amount' => $request->amount,
                'purpose' => $request->purpose,
                'remarks' => $request->remarks,
            ]);

        if ($validationResponse->failed()) {
            Log::warning('validateChatPaymentRecipient failed', [
                'status' => $validationResponse->status(),
                'payload' => $validationResponse->json(),
            ]);
            return response()->json([
                'error' => 'Recipient validation failed.',
                'details' => $validationResponse->json()
            ], 400);
        }

        $payload = $validationResponse->json() ?: [];
        if (!$this->isValidationSuccessful($payload)) {
            return response()->json([
                'error' => 'Recipient validation was not approved by Sampay.',
                'details' => $payload,
            ], 400);
        }

        return response()->json($payload);
    }

    public function requestChatPayment(Request $request, $chat_id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'recipient_type' => 'required|string|in:personal,business',
            'recipient_account' => 'required|string|max:255',
            'purpose' => 'required|string|max:120',
            'remarks' => 'nullable|string|max:255',
            'recipient_user_id' => 'nullable|uuid',
        ]);

        if (!$this->isBaseUrlConfigured()) {
            return response()->json(['error' => 'Sampay service is not configured. Please contact support.'], 503);
        }

        $user = $request->user();
        $chat = $user->chats()->with('participants')->findOrFail($chat_id);

        if ($chat->chat_type === 'group' && !$request->filled('recipient_user_id')) {
            return response()->json(['error' => 'Select a group member to pay.'], 422);
        }

        $senderSampay = $this->linkedSampayAccountFor($user);
        if (!$senderSampay) {
            return response()->json(['error' => 'You must link (or relink) your Sampay account first.'], 400);
        }

        $targetParticipant = $this->resolveChatPaymentTarget($request, $chat);
        if (!$targetParticipant) {
            return response()->json(['error' => 'Unable to determine target user for this chat.'], 400);
        }

        $validationResponse = Http::withToken($senderSampay->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->post($this->getBaseUrl() . '/api/v1/external-payments/validate-recipient', [
                'recipient_type' => $request->recipient_type,
                'accounttype' => $request->recipient_type,
                'recipient_account' => $request->recipient_account,
                'amount' => $request->amount,
                'purpose' => $request->purpose,
                'remarks' => $request->remarks,
            ]);

        if ($validationResponse->failed()) {
            Log::warning('requestChatPayment recipient validation failed', [
                'status' => $validationResponse->status(),
                'payload' => $validationResponse->json(),
            ]);
            return response()->json([
                'error' => 'Recipient validation failed before creating request.',
                'details' => $validationResponse->json()
            ], 400);
        }

        $validatedPayload = $validationResponse->json() ?: [];
        if (!$this->isValidationSuccessful($validatedPayload)) {
            return response()->json([
                'error' => 'Recipient validation was not approved by Sampay.',
                'details' => $validatedPayload,
            ], 400);
        }

        $validatedData = $this->extractValidationData($validatedPayload);

        Log::info('External payment request received', [
            'chat_id' => $chat_id,
            'requester_user_id' => $user->id,
            'recipient_type' => $request->recipient_type,
            'recipient_account' => $request->recipient_account,
            'amount' => $request->amount,
            'purpose' => $request->purpose,
        ]);

        $externalRequestResponse = Http::withToken($senderSampay->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->post($this->getBaseUrl() . '/api/v1/external-payments/request', [
                'recipient_type' => $request->recipient_type,
                'accounttype' => $request->recipient_type,
                'recipient_account' => $request->recipient_account,
                'amount' => $request->amount,
                'purpose' => $request->purpose,
                'remarks' => $request->remarks,
                'description' => $request->purpose,
            ]);

        if ($externalRequestResponse->failed()) {
            Log::warning('External payment request failed', [
                'chat_id' => $chat_id,
                'requester_user_id' => $user->id,
                'status' => $externalRequestResponse->status(),
                'payload' => $externalRequestResponse->json(),
            ]);

            return response()->json([
                'error' => 'Failed to create external payment request after validation.',
                'details' => $externalRequestResponse->json(),
            ], 400);
        }

        $externalRequestPayload = $externalRequestResponse->json() ?: [];
        Log::info('External payment request created', [
            'chat_id' => $chat_id,
            'requester_user_id' => $user->id,
            'sampay_request_id' => $externalRequestPayload['request']['id'] ?? null,
            'payload' => $externalRequestPayload,
        ]);

        $metadata = [
            'amount' => (float) $request->amount,
            'recipient_type' => $request->recipient_type,
            'recipient_account' => $request->recipient_account,
            'purpose' => $request->purpose,
            'remarks' => $request->remarks,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'target_user_id' => $targetParticipant->user_id,
            'recipient_preview' => $validatedData['recipient'] ?? null,
            'request_preview' => $validatedData['request_preview'] ?? ($validatedData['preview'] ?? null),
            'sampay_request_id' => $externalRequestPayload['request']['id'] ?? null,
            'requested_at' => now()->toISOString(),
            'submitted_to_sampay_at' => now()->toISOString(),
        ];

        DB::beginTransaction();
        try {
            $message = $chat->messages()->create([
                'sender_id' => $user->id,
                'message_type' => 'payment_request',
                'content' => 'Payment request for ZMW ' . number_format((float) $request->amount, 2, '.', ''),
                // Message::$casts already declares 'metadata' => 'array' —
                // it encodes on write by itself, so json_encode()'ing here
                // too double-encoded it (same bug fixed in
                // ChatController::storeMessage).
                'metadata' => $metadata,
            ]);

            $chat->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ]);

            $chat->participants()->where('user_id', '!=', $user->id)->increment('unread_count');
            DB::commit();

            $messageSentEvent = new MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json([
                'message' => 'Payment request validated and created on Sampay.',
                'chat_message' => $message,
                'sampay_response' => $externalRequestPayload,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create in-chat payment request.'], 500);
        }
    }

    public function syncChatPaymentStatuses(Request $request, $chat_id)
    {
        if (!$this->isBaseUrlConfigured()) {
            return response()->json(['error' => 'Sampay service is not configured. Please contact support.'], 503);
        }

        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        $messages = Message::where('chat_id', $chat->id)
            ->where('message_type', 'payment_request')
            ->latest('created_at')
            ->limit(80)
            ->get();

        $updatedMessages = [];
        $tokenOwnerAccountMap = [];

        foreach ($messages as $message) {
            $metadata = is_array($message->metadata)
                ? $message->metadata
                : (json_decode((string) $message->metadata, true) ?: []);

            $sampayRequestId = $metadata['sampay_request_id'] ?? null;
            $currentStatus = $metadata['status'] ?? 'pending';

            if (!$sampayRequestId) {
                continue;
            }

            if ($this->wasRecentlyChecked($metadata)) {
                continue;
            }

            if (!in_array($currentStatus, ['pending', 'submitted_to_sampay', 'pending_approval'], true)) {
                continue;
            }

            $tokenOwnerId = $metadata['requested_by_user_id'] ?? $message->sender_id;
            if (!array_key_exists($tokenOwnerId, $tokenOwnerAccountMap)) {
                $tokenOwner = User::find($tokenOwnerId);
                $tokenOwnerAccountMap[$tokenOwnerId] = $this->linkedSampayAccountFor($tokenOwner) ?? false;
            }
            $tokenOwnerSampay = $tokenOwnerAccountMap[$tokenOwnerId];

            if (!$tokenOwnerSampay) {
                continue;
            }

            $statusResponse = Http::withToken($tokenOwnerSampay->access_token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->get($this->getBaseUrl() . '/api/v1/external-payments/' . $sampayRequestId . '/status');

            if ($statusResponse->failed()) {
                Log::warning('Failed to fetch external payment status', [
                    'chat_id' => $chat_id,
                    'message_id' => $message->id,
                    'sampay_request_id' => $sampayRequestId,
                    'status' => $statusResponse->status(),
                    'payload' => $statusResponse->json(),
                ]);
                continue;
            }

            $statusPayload = $statusResponse->json() ?: [];
            $statusData = $statusPayload['data']['request'] ?? $statusPayload['request'] ?? null;
            $remoteStatus = $statusData['status'] ?? null;

            if (!$remoteStatus) {
                continue;
            }

            $nextMetadata = $metadata;
            $hasUpdate = false;

            if ($remoteStatus !== $currentStatus) {
                $nextMetadata['status'] = $remoteStatus;
                $hasUpdate = true;
            }

            if (isset($statusData['reference']) && (($metadata['reference'] ?? null) !== $statusData['reference'])) {
                $nextMetadata['reference'] = $statusData['reference'];
                $hasUpdate = true;
            }

            if (isset($statusPayload['data']['wallet_effects']) && (($metadata['wallet_effects'] ?? null) !== $statusPayload['data']['wallet_effects'])) {
                $nextMetadata['wallet_effects'] = $statusPayload['data']['wallet_effects'];
                $hasUpdate = true;
            }

            if (!$hasUpdate) {
                continue;
            }

            $nextMetadata['status_checked_at'] = now()->toISOString();
            $nextMetadata['updated_at'] = now()->toISOString();

            $message->metadata = $nextMetadata;
            $message->save();

            $updatedMessages[] = $message;
            $messageSentEvent = new MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);
        }

        return response()->json([
            'message' => 'Payment statuses synced.',
            'updated_messages' => $updatedMessages,
        ]);
    }

    public function approveChatPayment(Request $request, $chat_id, $message_id)
    {
        if (!$this->isBaseUrlConfigured()) {
            return response()->json(['error' => 'Sampay service is not configured. Please contact support.'], 503);
        }

        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        $message = Message::where('id', $message_id)
            ->where('chat_id', $chat->id)
            ->where('message_type', 'payment_request')
            ->firstOrFail();

        $metadata = is_array($message->metadata) ? $message->metadata : (json_decode((string) $message->metadata, true) ?: []);

        if (($metadata['target_user_id'] ?? null) !== $user->id) {
            return response()->json(['error' => 'Only the targeted user can approve this payment request.'], 403);
        }

        if (($metadata['status'] ?? null) !== 'pending_approval') {
            return response()->json(['error' => 'This payment request is no longer pending approval.'], 400);
        }

        $approverSampay = $this->linkedSampayAccountFor($user);
        if (!$approverSampay) {
            return response()->json(['error' => 'Please link (or relink) your Sampay account before approving this request.'], 400);
        }

        $amount = (float) ($metadata['amount'] ?? 0);
        $recipientType = $metadata['recipient_type'] ?? null;
        $recipientAccount = $metadata['recipient_account'] ?? null;
        $purpose = $metadata['purpose'] ?? null;
        $remarks = $metadata['remarks'] ?? null;
        if ($amount < 1 || !$recipientType || !$recipientAccount || !$purpose) {
            return response()->json(['error' => 'Payment request metadata is invalid.'], 400);
        }

        $validationResponse = Http::withToken($approverSampay->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->post($this->getBaseUrl() . '/api/v1/external-payments/validate-recipient', [
                'recipient_type' => $recipientType,
                'accounttype' => $recipientType,
                'recipient_account' => $recipientAccount,
                'amount' => $amount,
                'purpose' => $purpose,
                'remarks' => $remarks,
            ]);

        if ($validationResponse->failed()) {
            $metadata['status'] = 'failed';
            $metadata['last_error'] = 'Recipient validation failed before submission.';
            $metadata['validation_details'] = $validationResponse->json();
            $metadata['acted_by_user_id'] = $user->id;
            $metadata['updated_at'] = now()->toISOString();

            $message->metadata = $metadata;
            $message->save();

            $messageSentEvent = new MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json([
                'error' => 'Recipient validation failed before submission.',
                'details' => $validationResponse->json(),
                'chat_message' => $message,
            ], 400);
        }

        $approvalValidationPayload = $validationResponse->json() ?: [];
        if (!$this->isValidationSuccessful($approvalValidationPayload)) {
            $metadata['status'] = 'failed';
            $metadata['last_error'] = 'Recipient validation was not approved by Sampay.';
            $metadata['validation_details'] = $approvalValidationPayload;
            $metadata['acted_by_user_id'] = $user->id;
            $metadata['updated_at'] = now()->toISOString();

            $message->metadata = $metadata;
            $message->save();

            $messageSentEvent = new MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json([
                'error' => 'Recipient validation was not approved by Sampay.',
                'details' => $approvalValidationPayload,
                'chat_message' => $message,
            ], 400);
        }

        $response = Http::withToken($approverSampay->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->post($this->getBaseUrl() . '/api/v1/external-payments/request', [
                'recipient_type' => $recipientType,
                'accounttype' => $recipientType,
                'recipient_account' => $recipientAccount,
                'amount' => $amount,
                'purpose' => $purpose,
                'remarks' => $remarks,
                'description' => $purpose,
            ]);

        if ($response->failed()) {
            Log::warning('approveChatPayment external request failed', [
                'status' => $response->status(),
                'payload' => $response->json(),
                'chat_id' => $chat_id,
                'message_id' => $message_id,
            ]);
            $metadata['status'] = 'failed';
            $metadata['last_error'] = $response->json('error') ?: 'Failed to submit to Sampay';
            $metadata['acted_by_user_id'] = $user->id;
            $metadata['updated_at'] = now()->toISOString();

            $message->metadata = $metadata;
            $message->save();

            $messageSentEvent = new MessageSent($message);
            $messageSentEvent->dontBroadcastToCurrentUser();
            event($messageSentEvent);

            return response()->json([
                'error' => 'Failed to submit payment request to Sampay.',
                'details' => $response->json(),
                'chat_message' => $message,
            ], 400);
        }

        $sampayPayload = $response->json();
        $metadata['status'] = 'submitted_to_sampay';
        $metadata['sampay_request_id'] = $sampayPayload['request']['id'] ?? null;
        $metadata['approved_by_user_id'] = $user->id;
        $metadata['approved_at'] = now()->toISOString();
        $metadata['updated_at'] = now()->toISOString();

        $message->metadata = $metadata;
        $message->save();

        $messageSentEvent = new MessageSent($message);
        $messageSentEvent->dontBroadcastToCurrentUser();
        event($messageSentEvent);

        return response()->json([
            'message' => 'Payment request approved and submitted to Sampay.',
            'chat_message' => $message,
            'sampay_response' => $sampayPayload,
        ]);
    }

    public function rejectChatPayment(Request $request, $chat_id, $message_id)
    {
        $user = $request->user();
        $chat = $user->chats()->findOrFail($chat_id);

        $message = Message::where('id', $message_id)
            ->where('chat_id', $chat->id)
            ->where('message_type', 'payment_request')
            ->firstOrFail();

        $metadata = is_array($message->metadata) ? $message->metadata : (json_decode((string) $message->metadata, true) ?: []);

        if (($metadata['target_user_id'] ?? null) !== $user->id) {
            return response()->json(['error' => 'Only the targeted user can reject this payment request.'], 403);
        }

        if (($metadata['status'] ?? null) !== 'pending_approval') {
            return response()->json(['error' => 'This payment request is no longer pending approval.'], 400);
        }

        $metadata['status'] = 'rejected';
        $metadata['rejected_by_user_id'] = $user->id;
        $metadata['rejected_at'] = now()->toISOString();
        $metadata['updated_at'] = now()->toISOString();

        $message->metadata = $metadata;
        $message->save();

        $messageSentEvent = new MessageSent($message);
        $messageSentEvent->dontBroadcastToCurrentUser();
        event($messageSentEvent);

        return response()->json([
            'message' => 'Payment request rejected.',
            'chat_message' => $message,
        ]);
    }
}
