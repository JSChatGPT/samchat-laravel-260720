<?php

namespace App\Services;

/**
 * A provider capable of delivering a one-time-password SMS — implemented by
 * MtnSmsService and ZamtelSmsService. OtpService depends on this, not on
 * either concrete class, so the active provider is just a config value
 * (services.sms.default / SMS_PROVIDER in .env — see AppServiceProvider)
 * rather than a code change.
 */
interface SmsGatewayInterface
{
    public function sendOtp(string $phoneE164, string $code): bool;
}
