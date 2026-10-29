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

    'mailpulse' => [
        // Desactive jusqu'a ce que la chaine outbox soit validee en staging.
        'enabled' => env('MAILPULSE_ENABLED', false),
        'base_url' => env('MAILPULSE_BASE_URL', ''),
        'api_key' => env('MAILPULSE_API_KEY', ''),
        'timeout' => env('MAILPULSE_TIMEOUT', 10),
    ],

    'group_sso' => [
        'secret' => env('GROUP_SSO_SHARED_SECRET'),
    ],

];
