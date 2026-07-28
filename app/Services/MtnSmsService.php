<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MTN Ngage Enterprise Messaging (https://cpassmessaging.mtn.zm) — sends the
 * real OTP SMS for phone verification. JWT bearer auth: log in once with the
 * enterprise account's email/password to get an access token, cache it, and
 * reuse it until it's rejected or the cache entry lapses.
 */
class MtnSmsService implements SmsGatewayInterface
{
    private const TOKEN_CACHE_KEY = 'mtn_sms_access_token';

    // The API docs don't state the JWT's actual lifetime, so this is a
    // conservative guess — short enough that a genuinely-expired token is
    // unlikely to still be cached, long enough to avoid logging in again on
    // every single OTP send.
    private const TOKEN_TTL_MINUTES = 50;

    public function sendOtp(string $phoneE164, string $code): bool
    {
        // MTN wants digits only (country code, no '+') — PhoneNumber::toE164
        // always produces "+<digits>".
        $recipient = ltrim($phoneE164, '+');
        $message = "Your Samchat verification code is {$code}. It expires in 5 minutes.";

        try {
            $response = $this->send($recipient, $message);

            // A cached token that's actually expired/revoked comes back as
            // 401 — refresh it once and retry, rather than failing an
            // otherwise-valid OTP request over a stale token.
            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                $response = $this->send($recipient, $message);
            }

            if (!$response->successful()) {
                Log::error('MTN SMS send failed', ['status' => $response->status(), 'body' => $response->body()]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('MTN SMS send threw', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function send(string $recipient, string $message): Response
    {
        return Http::withToken($this->accessToken())
            ->timeout(10)
            ->post($this->url('/api/v1/sms/send'), [
                'msg' => $message,
                'recipient' => $recipient,
                'sender' => config('services.mtn_sms.sender_id'),
                'category' => 'OTP',
            ]);
    }

    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(self::TOKEN_TTL_MINUTES), function () {
            $response = Http::timeout(10)->post($this->url('/api/v1/accounts/users/login'), [
                'email' => config('services.mtn_sms.email'),
                'password' => config('services.mtn_sms.password'),
            ]);

            if (!$response->successful()) {
                Log::error('MTN SMS login failed', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('Could not authenticate with MTN messaging API');
            }

            return (string) $response->json('access_token');
        });
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.mtn_sms.base_url'), '/') . $path;
    }
}
