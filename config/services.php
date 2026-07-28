<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sampay' => [
        'base_url' => env('SAMPAY_BASE_URL'),
        'client_id' => env('SAMPAY_CLIENT_ID'),
        'client_secret' => env('SAMPAY_CLIENT_SECRET'),
        'redirect_uri' => env('SAMPAY_REDIRECT_URI'),
    ],

    // Cloudflare Realtime TURN — mints short-lived TURN credentials so calls
    // can relay through Cloudflare's network from anywhere, not just whatever
    // LAN the backend happens to be on. Create a free "Realtime TURN" App at
    // https://dash.cloudflare.com (Realtime > TURN Service) to get these two
    // values. See CallController::turnCredentials.
    'cloudflare_turn' => [
        'key_id' => env('CLOUDFLARE_TURN_KEY_ID'),
        'api_token' => env('CLOUDFLARE_TURN_API_TOKEN'),
    ],

    // Which SMS gateway OtpService uses to deliver OTP codes — 'mtn' or
    // 'zamtel'. See AppServiceProvider's SmsGatewayInterface binding.
    'sms' => [
        'default' => env('SMS_PROVIDER', 'mtn'),
    ],

    // MTN Ngage Enterprise Messaging (https://cpassmessaging.mtn.zm) — sends
    // the real OTP SMS for phone verification. See App\Services\MtnSmsService.
    // Requires an enterprise account (email/password, used to obtain a JWT)
    // and a registered sender ID.
    'mtn_sms' => [
        'base_url' => env('MTN_SMS_BASE_URL', 'https://cpassmessaging.mtn.zm'),
        'email' => env('MTN_SMS_EMAIL'),
        'password' => env('MTN_SMS_PASSWORD'),
        'sender_id' => env('MTN_SMS_SENDER_ID'),
    ],

    // Zamtel Bulk SMS (https://bulksms.zamtel.co.zm) — alternative OTP
    // delivery provider, selected via SMS_PROVIDER=zamtel above. See
    // App\Services\ZamtelSmsService.
    'zamtel_sms' => [
        'base_url' => env('ZAMTEL_SMS_BASE_URL', 'https://bulksms.zamtel.co.zm'),
        'api_key' => env('ZAMTEL_SMS_API_KEY'),
        'sender_id' => env('ZAMTEL_SMS_SENDER_ID'),
    ],

    // A single fixed phone number + code that Play/App Store reviewers can
    // sign in with, without a real SMS round trip to a device they don't
    // control — see OtpService. test_phone must be a real, already
    // registered user (create one via the normal /auth/register flow) so
    // verifyOtp's user lookup still succeeds. This is the one deliberate
    // exception to "every OTP is freshly generated and delivered by SMS";
    // it never applies to any other number.
    'play_review' => [
        'test_phone' => env('PLAY_REVIEW_TEST_PHONE'),
        'test_otp' => env('PLAY_REVIEW_TEST_OTP'),
    ],

];
