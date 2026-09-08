<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    /*
    | Wartości domyślne są NASZE, nie Laravelowe (issue #79).
    |
    | Na Railway zmienne środowiskowe ustawia człowiek i człowiek potrafi ich
    | nie ustawić — a wtedy zostaje to, co stoi w tej linii. Domyślne
    | „Laravel <hello@example.com>" pod listem z linkiem do zmiany hasła to
    | gotowy phishing: nieznany nadawca, obca nazwa, prośba o kliknięcie.
    |
    | Adres nadawcy jest skrzynką, na którą DA SIĘ odpisać. Żadnego
    | `noreply@` — patrz docs/decyzje/POCZTA.md i docs/brand/BRAND_EXTENDED.md.
    |
    | NAZWA NADAWCY NIESIE IMIĘ GOSPODARZA, NIE „Zespół Kuking" — decyzja
    | właściciela, patrz `docs/brand/COPY_STYLE.md` §6 („nadawca") i
    | `docs/product/RETENTION_LOOPS.md` §4. Samo imię mieszka w JEDNYM
    | miejscu, `config('kuking.community.host_name')` — nigdy tu wpisane
    | wprost, żeby zmiana gospodarza była jedną linijką w `config/kuking.php`,
    | nie przeszukiwaniem configów maila i szablonów. `MAIL_FROM_NAME`
    | w środowisku dalej wygrywa, gdyby trzeba było nadpisać to doraźnie.
    */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'kontakt@kuking.pl'),
        'name' => env('MAIL_FROM_NAME', config('kuking.community.host_name').' z Kuking'),
    ],

];
