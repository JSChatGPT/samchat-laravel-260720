<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Real phone-verification OTPs — replaces the previous AuthController logic
 * that accepted the literal string "123456" for any phone number
 * unconditionally (a full account-takeover hole: anyone who knew a user's
 * phone number could sign in as them, no SMS interception needed). Codes
 * are randomly generated, hashed at rest, single-use, short-lived, and
 * rate-limited per phone number.
 *
 * The one deliberate exception is services.play_review.test_phone — a
 * single fixed number+code pair for Play/App Store reviewers, who can't
 * receive a real SMS to a device they don't control. That number must
 * already be a registered user like any other account; the bypass never
 * applies to any other number.
 */
class OtpService
{
    private const CODE_TTL_MINUTES = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_VERIFY_ATTEMPTS = 5;

    public function __construct(private SmsGatewayInterface $sms)
    {
    }

    /**
     * Request an OTP for the given phone number.
     *
     * @return array{status: string, code: string|null}
     *   'status' is 'sent' or 'cooldown' (asked again too soon).
     *   'code'   is the plain-text code that was sent (null on cooldown or
     *            for the review-test phone, where no code is generated).
     */
    public function request(string $phoneE164): array
    {
        if ($this->isReviewTestPhone($phoneE164)) {
            // Nothing to send — the fixed test code already "works" without
            // a round trip, see verify().
            return ['status' => 'sent', 'code' => null];
        }

        $cooldownKey = "otp_cooldown:{$phoneE164}";
        if (Cache::has($cooldownKey)) {
            return ['status' => 'cooldown', 'code' => null];
        }
        Cache::put($cooldownKey, true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));

        $code = (string) random_int(100000, 999999);
        Cache::put($this->codeKey($phoneE164), Hash::make($code), now()->addMinutes(self::CODE_TTL_MINUTES));
        Cache::forget($this->attemptsKey($phoneE164));

        $this->sms->sendOtp($phoneE164, $code);

        return ['status' => 'sent', 'code' => $code];
    }

    public function verify(string $phoneE164, string $submittedCode): bool
    {
        if ($this->isReviewTestPhone($phoneE164)) {
            $expected = (string) config('services.play_review.test_otp');
            return $expected !== '' && hash_equals($expected, $submittedCode);
        }

        $attemptsKey = $this->attemptsKey($phoneE164);
        $attempts = (int) Cache::get($attemptsKey, 0);
        if ($attempts >= self::MAX_VERIFY_ATTEMPTS) {
            return false;
        }

        $hashed = Cache::get($this->codeKey($phoneE164));
        if ($hashed === null || !Hash::check($submittedCode, $hashed)) {
            Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(self::CODE_TTL_MINUTES));
            return false;
        }

        // Single-use — a verified code can't be replayed.
        Cache::forget($this->codeKey($phoneE164));
        Cache::forget($attemptsKey);
        return true;
    }

    private function isReviewTestPhone(string $phoneE164): bool
    {
        $testPhone = (string) config('services.play_review.test_phone');
        return $testPhone !== '' && $phoneE164 === $testPhone;
    }

    private function codeKey(string $phoneE164): string
    {
        return "otp_code:{$phoneE164}";
    }

    private function attemptsKey(string $phoneE164): string
    {
        return "otp_attempts:{$phoneE164}";
    }
}
