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

    'openai' => [
        'key' => env('OPEN_AI_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1/'),
    ],

    'social' => [
        'google' => [
            'clientId' => env('GOOGLE_CLIENT_ID'),
        ],
        'apple' => [
            'clientId' => env('APPLE_CLIENT_ID'),
        ],
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'database' => env('FIRESTORE_DATABASE'),
        'fcm' => [
            'credentials' => 'firebase/wiseforkapp-36292-firebase-adminsdk-fbsvc-087f121c09.json',
        ],
    ],

    'instacart' => [
        'base_url' => env('INSTACART_BASE_URL'),
        'key' => env('INSTACART_API_KEY'),
    ],

    'apple' => [
        'base_url' => env('APPLE_BASE_URL'),
        'secret' => env('APPLE_SHARED_SECRET'),
    ],
];
