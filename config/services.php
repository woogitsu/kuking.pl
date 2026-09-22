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

    /*
    | EMAILLABS — POCZTA PRODUKCYJNA PRZEZ API HTTPS (D-047).
    |
    | Dwa klucze, nie jeden, bo tak wygląda uwierzytelnienie tego API: żądanie
    | niesie nagłówek `Application-Key` (klucz aplikacji) ORAZ `Authorization`
    | (klucz autoryzacyjny, 128 znaków). Oba generuje się razem w panelu
    | EmailLabs: Konto → Ustawienia → API → „Generuj klucz API".
    |
    | TO NIE SĄ LOGIN I HASŁO SMTP. Dane SMTP z sekcji „Konta SMTP" panelu
    | służą wyłącznie do wysyłki portem 587 — API ich nie przyjmie i odpowie
    | 401. Pomylenie jednego z drugim jest tu najbardziej prawdopodobnym
    | błędem konfiguracji, dlatego nazwy zmiennych zaczynają się od
    | `EMAILLABS_`, a nie od `MAIL_`.
    |
    | `EMAILLABS_SMTP_ACCOUNT` to mimo nazwy pole API: identyfikator konta
    | wysyłkowego w kształcie `1.nazwa.smtp`, wymagane pole `smtpAccount`
    | w każdym żądaniu. Znajdziesz je w panelu przy koncie SMTP.
    |
    | `EMAILLABS_TRACKING` domyślnie WYŁĄCZONE: włączone śledzenie podmienia
    | każdy odnośnik w liście na adres przekierowujący dostawcy, a link do
    | zmiany hasła prowadzący pod obcą domenę wygląda dla osoby 60+ dokładnie
    | jak phishing, przed którym ostrzegają banki.
    */
    'emaillabs' => [
        'key' => env('EMAILLABS_APP_KEY'),
        'secret' => env('EMAILLABS_SECRET_KEY'),
        'smtp_account' => env('EMAILLABS_SMTP_ACCOUNT'),
        'endpoint' => env('EMAILLABS_ENDPOINT', 'https://api.emaillabs.io/v2.1/email'),
        'timeout' => env('EMAILLABS_TIMEOUT', 15),
        'tracking' => env('EMAILLABS_TRACKING', false),
    ],

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
