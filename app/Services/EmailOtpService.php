<?php

namespace App\Services;

use App\Mail\OtpMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers an OTP code to an email address.
 *
 * The code itself is generated and cached by OtpService (phone-based); this
 * service only handles the email delivery side.  Both channels use the same
 * code so the user can verify with whichever they receive first.
 */
class EmailOtpService
{
    /**
     * Send the given $code to $email.
     *
     * Failures are logged but never bubble up — a broken mail transport
     * must not prevent the SMS OTP from being issued or the API from
     * responding.
     *
     * @param  string $email        The recipient address.
     * @param  string $code         The plain-text 6-digit code.
     * @param  int    $ttlMinutes   Expiry communicated to the user (must match OtpService::CODE_TTL_MINUTES).
     */
    public function send(string $email, string $code, int $ttlMinutes = 5): void
    {
        try {
            Mail::to($email)->send(new OtpMail($code, $ttlMinutes));
            Log::info('Email OTP sent', ['email' => $this->maskEmail($email)]);
        } catch (\Throwable $e) {
            Log::error('Email OTP send failed', [
                'email' => $this->maskEmail($email),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mask an email address for safe inclusion in API responses and logs.
     * e.g. "john.doe@gmail.com" → "j***@gmail.com"
     */
    public function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = substr($local, 0, 1);
        $masked  = $visible . str_repeat('*', max(3, mb_strlen($local) - 1));

        return $masked . '@' . $domain;
    }
}
