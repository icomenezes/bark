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

    'evolution' => [
        'url' => env('EVOLUTION_API_URL'),
        'instance' => env('EVOLUTION_API_INSTANCE'),
        'key' => env('EVOLUTION_API_KEY'),
    ],

    // Assinatura PAdES — caminho do binário pyHanko (opcional; sem ele usa fallback TCPDF)
    'pyhanko' => [
        'bin' => env('PYHANKO_BIN'),
    ],

    // CLI openssl para converter PFX legado (RC2/3DES) que o OpenSSL 3 do PHP não lê
    'openssl' => [
        'bin' => env('OPENSSL_BIN'),
    ],

    // CLI qpdf para reparar a tabela xref de PDFs gerados pelo TCPDF (parser do pyHanko é estrito)
    'qpdf' => [
        'bin' => env('QPDF_BIN'),
    ],

];
