<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    | GKash payment gateway (https://doc.gkash.my).
    | Sandbox: https://api-staging.pay.asia   Production: https://api.gkash.my
    | On the servers these come from SSM Parameter Store (/lbmis/<env>/GKASH_*).
    */
    'gkash' => [
        'base_url' => rtrim((string) env('GKASH_BASE_URL', 'https://api-staging.pay.asia'), '/'),
        'cid' => env('GKASH_CID'),
        'signature_key' => env('GKASH_SIGNATURE_KEY'),
        'timeout' => (int) env('GKASH_TIMEOUT', 30),
    ],

];
