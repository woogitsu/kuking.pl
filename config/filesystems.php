<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * =====================================================================
         *  DWA BUCKETY R2, NIE JEDEN. TO JEST GRANICA BEZPIECZEŃSTWA.
         * =====================================================================
         *
         * Wcześniej był jeden dysk `r2`, jeden bucket i jeden `AWS_URL`.
         * Oryginały leżały w nim pod prefiksem `incoming/` zapisane jako
         * „private", warianty pod `media/` jako „public" — i cała prywatność
         * oryginałów opierała się na tym rozróżnieniu.
         *
         * NA R2 TO ROZRÓŻNIENIE NIE ISTNIEJE. Cloudflare nie implementuje
         * S3-owych ACL na obiektach: `x-amz-acl` jest w tabeli zgodności
         * oznaczony jako nieobsługiwany dla `PutObject`. Publiczność w R2
         * jest cechą BUCKETU (własna domena albo `r2.dev`), nie obiektu.
         * Bucket wystawiony pod `cdn.kuking.pl` wystawia więc CAŁĄ zawartość,
         * razem z `incoming/`.
         *
         * A adres oryginału daje się wyprowadzić z publicznego adresu wariantu:
         * ten sam UUID właściciela, ta sama data, ten sam UUID pliku —
         * wystarczy zamienić `media/` na `incoming/`, uciąć `_feed` i zgadnąć
         * rozszerzenie z czterech możliwych. W oryginale siedzi pełny EXIF,
         * czyli współrzędne GPS kuchni, w której zrobiono zdjęcie.
         *
         * Dlatego oryginały i warianty leżą w OSOBNYCH BUCKETACH:
         *
         *   r2            oryginały. Bez `url`, bez własnej domeny,
         *                 `r2.dev` wyłączone. Dostęp wyłącznie przez API S3
         *                 z serwera.
         *   r2_publiczne  przetworzone warianty WebP (bez EXIF). Ten i tylko
         *                 ten bucket ma `cdn.kuking.pl`.
         *
         * Nazwa `r2` zostaje dla oryginałów, bo tak ma w kolumnie `disk` każde
         * zdjęcie zapisane do tej pory i tam te oryginały fizycznie leżą.
         *
         * BRAK `url` W DYSKU ORYGINAŁÓW JEST CELOWY. `Storage::disk('r2')->url()`
         * rzuci wtedy wyjątek zamiast po cichu zwrócić publiczny adres pliku,
         * który publiczny być nie może. Pilnuje tego `RozdzialMagazynowTest`.
         *
         * `throw => true` w odróżnieniu od dysku `s3` niżej. Przy `false`
         * nieudany zapis zwraca `false`, a kod leci dalej: użytkownik widzi
         * „opublikowano", w bazie powstaje wiersz Media, a pliku nie ma nigdzie.
         * Wyjątek jest tu uczciwszy — lepiej pokazać błąd niż skasować
         * komuś zdjęcie i nie powiedzieć.
         *
         * `use_path_style_endpoint => false`: R2 adresuje bucket przez host,
         * nie przez ścieżkę.
         */
        'r2' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            // Świadomie BEZ `url`. Patrz komentarz wyżej.
            'use_path_style_endpoint' => false,
            'throw' => true,
        ],

        /*
         * Bucket publiczny: wyłącznie przetworzone warianty WebP.
         *
         * Wszystko, co tu trafia, przeszło przez `ProcessUploadedImage`, czyli
         * zostało zdekodowane i zapisane od nowa — a to zdejmuje EXIF razem
         * z GPS-em. Nic, co przyszło od użytkownika w oryginalnej postaci, nie
         * ma prawa się tu znaleźć.
         *
         * `AWS_PUBLIC_BUCKET` domyślnie wraca do `AWS_BUCKET`, żeby środowisko
         * jeszcze nierozdzielone (staging sprzed tej zmiany) nie przestało
         * działać z dnia na dzień. To jest jednak stan PRZEJŚCIOWY i test
         * `RozdzialMagazynowTest` oblewa, gdy produkcja tak zostanie.
         */
        'r2_publiczne' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_PUBLIC_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'url' => env('AWS_URL'),
            'use_path_style_endpoint' => false,
            'throw' => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
