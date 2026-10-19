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

    // Where feedback for the developers goes: a problem report as soon as
    // it is sent, anything else once the administrator sends it on. Set in
    // the server's .env, so only whoever runs the server changes it; the
    // administrator sees it on the Feedback page but cannot.
    'feedback' => [
        'developer_email' => env('FEEDBACK_DEVELOPER_EMAIL'),
    ],

    // Where server errors are emailed (App\Support\ErrorAlerts). Falls back to
    // the feedback address above; with neither set no alert is sent. Local
    // and test runs send none unless in_local is on.
    'error_alerts' => [
        'email' => env('ERROR_ALERT_EMAIL'),
        'in_local' => (bool) env('ERROR_ALERT_IN_LOCAL', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
