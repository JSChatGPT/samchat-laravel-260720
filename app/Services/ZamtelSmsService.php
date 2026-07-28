<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zamtel Bulk SMS (https://bulksms.zamtel.co.zm) — alternative OTP delivery
 * provider, selected via services.sms.default / SMS_PROVIDER=zamtel in .env
 * (see AppServiceProvider's SmsGatewayInterface binding). Every dynamic
 * value is a literal URL path segment (no request body) — confirmed working
 * shape via a live curl example from the vendor:
 *   POST https://bulksms.zamtel.co.zm/api/v2.1/action/send/api_key/{key}/contacts/{phone}/senderId/{sender}/message/{url-encoded text}
 *   Content-Type: application/json
 *   Accept: application/json
 * (no brackets around the phone number, despite the PDF documentation's
 * example showing `contacts/[260950003929]` — the confirmed-working curl
 * example has no brackets, so that's what's used here.)
 *
 * Confirmed response shapes from live calls:
 *   success: HTTP 200 {"success": true, "responseText": "...", "statusCode": ...}
 *   failure: HTTP 500 {"message": "...", "errors": {"responseText": "..."}}
 * successful() therefore checks both the HTTP status and the body's
 * `success` flag — a 2xx with `success` missing/false is still a failure.
 */
class ZamtelSmsService implements SmsGatewayInterface
{
    public function sendOtp(string $phoneE164, string $code): bool
    {
        // Digits only, country code, no '+' — same convention as
        // MtnSmsService.
        $recipient = ltrim($phoneE164, '+');
        $message = "Your Samchat verification code is {$code}. It expires in 5 minutes.";

        $apiKey = (string) config('services.zamtel_sms.api_key');
        $senderId = (string) config('services.zamtel_sms.sender_id');
        $baseUrl = rtrim((string) config('services.zamtel_sms.base_url'), '/');

        // Only the message segment is percent-encoded — matches the vendor
        // example, where contacts/senderId are sent as literal path
        // segments but the message has %20/%3A/etc.
        $url = "{$baseUrl}/api/v2.1/action/send/api_key/{$apiKey}/contacts/{$recipient}/senderId/{$senderId}/message/"
            . rawurlencode($message);

        try {
            // Everything the API needs is already in the URL — POST with no
            // body, just the two headers from the vendor's curl example.
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->post($url);

            Log::info('Zamtel SMS send response', ['status' => $response->status(), 'body' => $response->body()]);

            if (!$response->successful() || $response->json('success') !== true) {
                Log::error('Zamtel SMS send failed', [
                    'status' => $response->status(),
                    'message' => $response->json('message') ?? $response->json('responseText') ?? $response->body(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Zamtel SMS send threw', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
