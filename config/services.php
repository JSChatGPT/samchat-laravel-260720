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

];
