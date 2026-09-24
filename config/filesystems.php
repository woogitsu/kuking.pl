<?php

declare(strict_types=1);

/*
 * Para poświadczeń R2 dla JEDNEGO bucketu (#617).
 *
 * Aplikacja MUSI mieć prawo kasować obiekty w bucketach zdjęć i paczek:
 * `EraseAccountData` usuwa zdjęcia natychmiast, a sprzątanie osieroconych
 * i kompensacja nieudanego wgrania też kasują. Tego prawa nie da się jej
 * odebrać bez złamania procedury usuwania danych. Da się natomiast zawęzić
 * JEGO ZASIĘG: każdy bucket może dostać własny token, zamiast jednego
 * `AWS_ACCESS_KEY_ID`, który kasuje wszędzie naraz. Kopia zdjęć NIGDY nie
 * dostaje tu żadnego tokenu z prawem zapisu — patrz
 * `docs/infra/DR_ZDJEC_R2.md`.
 *
 * Reguła jest jedna: para bucketu (`<PREFIKS>_ACCESS_KEY_ID`
 * + `<PREFIKS>_SECRET_ACCESS_KEY`) wygrywa, gdy są OBIE zmienne; gdy nie ma
 * żadnej, bucket bierze wspólne `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`
 * — dokładnie jak przed tą zmianą.
 *
 * POŁOWA PARY TO BŁĄD KONFIGURACJI, NIE CICHY POWRÓT DO WSPÓLNEGO TOKENU.
 * Klucz jednego tokenu z sekretem drugiego to podpis, którego R2 nie
 * przyjmie; a ciche wzięcie wspólnej pary dawałoby właścicielowi złudzenie,
 * że zawężenie działa. Dlatego wyjątek już przy ładowaniu konfiguracji
 * (także w `config:cache` podczas budowania), z nazwą brakującej zmiennej.
 */
$paraR2 = static function (string $prefiks): array {
    $klucz = (string) env($prefiks.'_ACCESS_KEY_ID', '');
    $sekret = (string) env($prefiks.'_SECRET_ACCESS_KEY', '');

    if ($klucz === '' && $sekret === '') {
        return ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY')];
    }

    if ($klucz === '' || $sekret === '') {
        $brakuje = $klucz === '' ? $prefiks.'_ACCESS_KEY_ID' : $prefiks.'_SECRET_ACCESS_KEY';

        throw new RuntimeException(
            "Ustaw {$brakuje} albo usuń drugą zmienną z pary {$prefiks}_*. "
            .'Połowa pary tokenu R2 nie zadziała, a powrót do wspólnego tokenu byłby po cichu.',
        );
    }

    return ['key' => $klucz, 'secret' => $sekret];
};

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
         *  STEROWNIK `r2`, NIE `s3` — ZAPIS BEZ `x-amz-acl` (issue #120).
         * =====================================================================
         *
         * Wszystkie cztery dyski R2 niżej mają `driver => 'r2'`. To własny
         * sterownik (`App\Support\Storage\R2Adapter`, rejestrowany
         * w `AppServiceProvider`), który różni się od wbudowanego `s3`
         * dokładnie jedną rzeczą: nie wysyła ACL.
         *
         * Wbudowany `s3` wysyłał je ZAWSZE. Zdjęcie trzeciego argumentu
         * z `put()` usunęło `public-read`, ale nie usunęło nagłówka —
         * `AwsS3V3Adapter::upload()` liczy ACL także wtedy, gdy nikt o nie
         * nie prosił, i wtedy wypada `private`. R2 nie obsługuje ACL na
         * obiektach w ogóle (`x-amz-acl` jest w tabeli zgodności Cloudflare
         * oznaczony jako nieobsługiwany dla `PutObject`), więc cała
         * prywatność oryginałów zależała od tego, jak cudza implementacja
         * zareaguje na nagłówek, którego nie obsługuje. Cloudflare tego nie
         * gwarantuje — i to była bramka przed wystawieniem produkcyjnego
         * bucketu pod `cdn.kuking.pl`.
         *
         * Pilnuje tego `ZapisDoR2BezAclTest`: przechwytuje prawdziwe żądanie
         * HTTP i oblewa, gdy wróci tam `x-amz-acl`.
         */

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
            'driver' => 'r2',
            ...$paraR2('AWS_ORIGINALS'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            // Świadomie BEZ `url`. Patrz komentarz wyżej.
            'use_path_style_endpoint' => false,
            'throw' => true,
        ],

        /*
         * Bucket wariantów: wyłącznie przetworzone pliki WebP.
         *
         * Wszystko, co tu trafia, przeszło przez `ProcessUploadedImage`, czyli
         * zostało zdekodowane i zapisane od nowa — a to zdejmuje EXIF razem
         * z GPS-em. Nic, co przyszło od użytkownika w oryginalnej postaci, nie
         * ma prawa się tu znaleźć.
         *
         * ======================================================================
         *  NAZWA `r2_publiczne` ZOSTAJE, ALE TEN BUCKET NIE JEST JUŻ PUBLICZNY
         *  (audyt W7-02, P0, prywatność).
         * ======================================================================
         *
         * Brak EXIF-u to nie to samo co „wolno pokazać każdemu". Wariant
         * przepisu prywatnego jest tak samo prywatny jak sam przepis, a
         * `recipes.source_scan_media_id` to skan odręcznej kartki z nazwiskami
         * i adresami. Dopóki bucket miał własną domenę CDN, adres takiego
         * pliku działał WIECZNIE i dla każdego: kto raz go skopiował, otwierał
         * zdjęcie po zablokowaniu, po cofnięciu obserwowania i po przełączeniu
         * przepisu na prywatny.
         *
         * Dlatego `url` STĄD ZNIKA — tak samo i z tego samego powodu, z jakiego
         * nigdy nie miały go dyski `r2` (oryginały) i `r2_eksporty` (paczki
         * RODO). `Storage::url()` rzuci teraz wyjątek zamiast po cichu zwrócić
         * adres, który nikogo o nic nie pyta.
         *
         * Adresem zdjęcia jest trasa aplikacji `media.show`: pyta Policy treści
         * nadrzędnej i przekierowuje (302) na adres podpisany kluczem S3, ważny
         * kilka minut (`kuking.media.signed_url_minutes`). Bajty dalej nie idą
         * przez PHP.
         *
         * CZEGO TO NIE ZAŁATWIA, I TRZEBA TO POWIEDZIEĆ WPROST: zdjęcie tego
         * klucza z konfiguracji nie zdejmuje własnej domeny z bucketu po
         * stronie Cloudflare. Dopóki `cdn.kuking.pl` wskazuje ten bucket, stare
         * adresy działają dalej. To jest ręczna czynność właściciela —
         * issue #120 — i z tego kontenera nie da się jej ani wykonać, ani
         * sprawdzić.
         *
         * `AWS_PUBLIC_BUCKET` domyślnie wraca do `AWS_BUCKET`, żeby środowisko
         * jeszcze nierozdzielone (staging sprzed tej zmiany) nie przestało
         * działać z dnia na dzień. To jest jednak stan PRZEJŚCIOWY i test
         * `RozdzialMagazynowTest` oblewa, gdy produkcja tak zostanie.
         */
        'r2_publiczne' => [
            'driver' => 'r2',
            ...$paraR2('AWS_PUBLIC'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_PUBLIC_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            // Świadomie BEZ `url`. Patrz komentarz wyżej (W7-02).
            'use_path_style_endpoint' => false,
            'throw' => true,
        ],

        /*
         * STARY, JEDEN BUCKET — dysk zgodności (audyt W4-03).
         *
         * Rozdzielenie oryginałów i wariantów było potrzebne, ale samo w sobie
         * NIE PRZENOSI plików. Wiersze zapisane wcześniej mają w kolumnie
         * `disk` wartość `r2` i tam ich pliki fizycznie leżą — w JEDNYM,
         * publicznym buckecie. Po przestawieniu `AWS_BUCKET` na nowy, prywatny
         * bucket ta sama nazwa `r2` zaczęłaby wskazywać miejsce, w którym tych
         * plików nie ma: warianty przestałyby się wyświetlać, a oryginałów nie
         * dałoby się dołączyć do paczki RODO.
         *
         * Komentarz w migracji `..._add_variants_disk_to_media` twierdził, że
         * „oba źródła współistnieją i kod obsługuje jedno i drugie". Nie było
         * to prawdą, dopóki nie istniał ten dysk — i jest to dokładnie ten
         * rodzaj komentarza pewniejszego niż kod, przed którym ostrzegał audyt.
         *
         * `url` TU ZOSTAJE, bo stary bucket jest publiczny i dopóki wariantów
         * z niego nie przeniesiemy, to stamtąd się wyświetlają. To jest stan
         * przejściowy: publiczności starego bucketu nie zdejmujemy, dopóki
         * `kuking:przenies-zdjecia` nie dojdzie do końca.
         *
         * PO W7-02 TO JEST JEDYNY DYSK ZDJĘĆ Z PUBLICZNYM ADRESEM i jedyny
         * powód, dla którego `PurgePublicMediaCache` nadal ma co robić.
         * Zapasowe `env('AWS_URL')` zostaje dla środowisk sprzed tej zmiany,
         * ale wdrożenie tej zmiennej już nie ustawia (`.railway/railway.ts`) —
         * gdzie stary bucket jest w użyciu, trzeba podać `AWS_LEGACY_URL`
         * wprost.
         */
        'r2_legacy' => [
            'driver' => 'r2',
            ...$paraR2('AWS_LEGACY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_LEGACY_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'url' => env('AWS_LEGACY_URL', env('AWS_URL')),
            'use_path_style_endpoint' => false,
            'throw' => true,
        ],

        /*
         * Paczki z danymi (RODO art. 15 i 20) — WŁASNY bucket, prywatny.
         *
         * Nie `local`, bo produkcja ma OSOBNE kontenery `web`, `worker`
         * i `scheduler`, bez wspólnego wolumenu. Paczkę buduje worker,
         * a pobranie obsługuje web — na dysku lokalnym plik powstawał więc
         * w jednym kontenerze, a szukano go w drugim. W bazie stało `ready`,
         * a człowiek dostawał 404. Restart workera i tak by ją zabrał
         * (audyt W3-01).
         *
         * Nie `r2_publiczne`, bo to jest kopia CAŁEGO konta: e-mail, wszystkie
         * treści, wszystkie zdjęcia z pełnym EXIF-em. Ten bucket nie ma i nie
         * może mieć własnej domeny — pobranie idzie WYŁĄCZNIE przez trasę
         * z podpisem, po sprawdzeniu, że pyta właściciel.
         *
         * Świadomie bez `url`, z tego samego powodu co dysk oryginałów:
         * `Storage::url()` ma wtedy rzucić wyjątek, a nie zwrócić adres,
         * pod którym leży czyjeś całe konto.
         */
        'r2_eksporty' => [
            'driver' => 'r2',
            ...$paraR2('AWS_EXPORTS'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_EXPORTS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => false,
            // `throw => true`: nieudany zapis paczki MUSI być błędem.
            // Przy `false` `writeStream()` zwraca `false`, job leci dalej,
            // rekord dostaje `ready`, a pliku nie ma nigdzie.
            'throw' => true,
        ],

        /*
         * =====================================================================
         *  KOPIE BAZY — DYSK TYLKO DO CZYTANIA (issue #193, decyzja D-043)
         * =====================================================================
         *
         * Kopie robi OSOBNY serwis Railway w obrazie bez PHP (`docker/kopia/`).
         * Aplikacja nie zapisuje tu NICZEGO i nie ma prawa: token R2 podany
         * w `AWS_KOPIE_*` ma mieć uprawnienie wyłącznie do odczytu tego
         * jednego bucketu.
         *
         * PO CO WIĘC APLIKACJI TEN DYSK: żeby dało się zauważyć, że kopie
         * PRZESTAŁY POWSTAWAĆ. Serwis kopii alarmuje, gdy jego przebieg się
         * nie udał — ale nie zaalarmuje, gdy przebiegu nie było (serwis
         * skasowany, harmonogram wyłączony, limit wyczerpany). Czujka
         * `kuking:sprawdz-kopie` listuje ten dysk raz na dobę i dzwoni, gdy
         * najnowsza kopia jest za stara.
         *
         * OSOBNY BUCKET I OSOBNE POŚWIADCZENIE, nie prefiks w buckecie zdjęć:
         * publiczność w R2 jest cechą BUCKETU (patrz komentarz przy `r2`
         * wyżej), a zrzut całej bazy w buckecie, który kiedykolwiek może
         * dostać własną domenę CDN, jest wypadkiem czekającym na swoją kolej.
         *
         * Świadomie BEZ `url` — z tego samego powodu, co `r2` i `r2_eksporty`.
         * `throw => false`: niedostępny bucket kopii nie może przewrócić
         * niczego w aplikacji; czujka sama zamienia porażkę odczytu w alarm.
         */
        'r2_kopie' => [
            'driver' => 's3',
            'key' => env('AWS_KOPIE_ACCESS_KEY_ID'),
            'secret' => env('AWS_KOPIE_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            // PUSTY ŁAŃCUCH, NIE `null`, gdy zmiennej nie ma. Adapter S3
            // wymaga nazwy bucketu typu `string` i przy `null` rzuca
            // TypeError już przy TWORZENIU dysku — a `KonfiguracjaDyskowTest`
            // tworzy wszystkie dyski z konfiguracji właśnie po to, żeby taki
            // błąd nie czekał na pierwsze użycie. Pozostałe dyski R2 mają to
            // z przypadku (`.env.example` deklaruje ich zmienne jako puste,
            // a `env()` oddaje wtedy `''`); tutaj mówimy to wprost, żeby
            // środowisko bez tej zmiennej nie wywracało się na starcie.
            // Pusta nazwa i tak znaczy „czujka wyłączona" (`kuking.kopie`).
            'bucket' => env('AWS_KOPIE_BUCKET', ''),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
         * KOPIA ZDJĘĆ — DYSK TYLKO DO CZYTANIA (#617, D-256).
         *
         * Migawki zdjęć robi proces POZA aplikacją (właściciel, z własnym
         * tokenem zapisu — `docs/infra/DR_ZDJEC_R2.md`). Aplikacja dostaje
         * tu wyłącznie token ODCZYTU, a korzysta z niego jedna komenda:
         * `kuking:sprawdz-kopie-zdjec`, która porównuje wiersze `media`
         * z migawką i niczego nie zapisuje.
         *
         * Gdyby aplikacja miała tu prawo zapisu, udany atak na nią kasowałby
         * razem z oryginałami także ich kopie — czyli dokładnie to, przed
         * czym ta warstwa ma chronić.
         *
         * Pusta nazwa bucketu = kopii nie ma (z tego samego powodu co przy
         * `r2_kopie`: `''`, nie `null`). Świadomie bez `url`.
         */
        'r2_kopia_zdjec' => [
            'driver' => 's3',
            'key' => env('AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID'),
            'secret' => env('AWS_ZDJECIA_KOPIA_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_ZDJECIA_KOPIA_BUCKET', ''),
            'endpoint' => env('AWS_ENDPOINT'),
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
