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

    'centralpay' => [
        'enabled'       => env('CENTRALPAY_ENABLED', false),
        'api_key'       => env('CENTRALPAY_API_KEY', ''),
        'base_url'      => env('CENTRALPAY_BASE_URL', 'https://centralapi.org/webservice/basic'),
        'type'          => env('CENTRALPAY_TYPE', 'deposit'),
        'amount_unit'   => env('CENTRALPAY_AMOUNT_UNIT', 'TOMAN'),
        'callback_path' => env('CENTRALPAY_CALLBACK_PATH', '/payments/centralpay/callback'),
    ],

];
