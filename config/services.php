<?php

declare(strict_types=1);

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

    /*
    | AMAZON SES MA WŁASNE ZMIENNE, A NIE `AWS_*`.
    |
    | Domyślnie Laravel czyta tu `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`
    | i `AWS_DEFAULT_REGION`. W Kuking te trzy nazwy NALEŻĄ DO CLOUDFLARE R2
    | (zdjęcia): `.railway/railway.ts` wpisuje w nie klucze R2 i region
    | ustawiony literalnie na `auto`, bo tego wymaga R2.
    |
    | Zostawienie domyślnych nazw znaczyłoby, że pierwsze `MAIL_MAILER=ses`
    | próbuje zalogować się do Amazona kluczem Cloudflare, w regionie, którego
    | Amazon nie ma. Błąd byłby przy tym mylący („credentials”, „endpoint”),
    | a poprawna diagnoza wymagałaby wiedzy, że dwie zupełnie różne usługi
    | dzielą tu jeden komplet zmiennych.
    |
    | Fallback na `AWS_*` zostaje, żeby nie zepsuć środowiska, w którym ktoś
    | wpisał je świadomie — ale kolejność jest jednoznaczna: poczta najpierw
    | pyta o SWOJE zmienne. Region domyślny to `eu-central-1` (Frankfurt),
    | bo dane mają zostawać w UE (RODO, `docs/decyzje/POCZTA.md` §2).
    */
    'ses' => [
        'key' => env('MAIL_SES_KEY', env('AWS_ACCESS_KEY_ID')),
        'secret' => env('MAIL_SES_SECRET', env('AWS_SECRET_ACCESS_KEY')),
        'region' => env('MAIL_SES_REGION', env('AWS_DEFAULT_REGION', 'eu-central-1')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
