<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfiguracja produktowa Kuking
|--------------------------------------------------------------------------
|
| Wszystkie liczby, które opisują REGUŁY PRODUKTU (a nie infrastrukturę),
| trzymamy tutaj — po to, żeby nie były rozsiane po kontrolerach i widokach.
| Zmiana progu = zmiana w jednym miejscu + test.
|
*/

return [

    'media' => [
        // Dysk Laravel Filesystem, na którym żyją zdjęcia. Dzięki temu przejście
        // z dysku lokalnego na Cloudflare R2 jest zmianą konfiguracji, nie kodu.
        //
        // Klucz mieszka TU, wewnątrz `media`, bo tak czyta go kod:
        // `config('kuking.media.disk')`. Wcześniej leżał obok, jako
        // `kuking.media_disk`, więc odczyt zwracał null — i nikt tego nie
        // zauważył, bo `Storage::disk('')` po cichu bierze dysk domyślny.
        // Cała zmienna KUKING_MEDIA_DISK nie robiła przez to nic.
        //
        // Domyślnie podążamy za `FILESYSTEM_DISK`, zamiast mieć własną wartość
        // domyślną: inaczej środowisko przestawione na R2 nadal trzymałoby
        // zdjęcia gdzie indziej, dopóki ktoś nie ustawi drugiej zmiennej.
        'disk' => env('KUKING_MEDIA_DISK', env('FILESYSTEM_DISK', 'public')),

        // 15 MB — tyle, żeby zdjęcie z telefonu przeszło bez kombinowania.
        'max_bytes' => (int) env('KUKING_MEDIA_MAX_BYTES', 15 * 1024 * 1024),

        // Ochrona przed "decompression bomb": plik może być mały, a obraz
        // gigantyczny. Limit liczony przed dekodowaniem całości.
        'max_megapixels' => (int) env('KUKING_MEDIA_MAX_MEGAPIXELS', 50),

        'accepted_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif',
            'image/heic',
            'image/heif',
        ],

        // Warianty generowane w tle (docs/MEDIA_PIPELINE.md).
        'variants' => [
            'thumb' => 320,
            'feed' => 960,
            'large' => 1600,
        ],

        // Maksymalna liczba zdjęć w jednym wpisie.
        'max_per_post' => 6,
    ],

    'feed' => [
        // Ile wpisów na "stronę". Bez infinite scroll — jest przycisk
        // "Pokaż więcej" (docs/UX_50_PLUS.md).
        'page_size' => (int) env('KUKING_FEED_PAGE_SIZE', 15),
    ],

    'text' => [
        // Skala tekstu ustawiana przez użytkownika w /settings/accessibility.
        // Wartości w procentach; muszą mieścić się w CHECK z migracji (90–140).
        'scales' => [100, 112, 125, 140],
        'default_scale' => 100,
    ],

    'account' => [
        'registration_open' => (bool) env('KUKING_REGISTRATION_OPEN', true),

        // Minimalny wiek. Oświadczenie przy rejestracji — bez weryfikacji
        // tożsamości (docs/legal/COMPLIANCE.md).
        'min_age' => (int) env('KUKING_MIN_AGE', 16),

        // Ile dni konto czeka w stanie 'pending_delete', zanim dane zostaną
        // trwale usunięte. Daje szansę na "pomyliłem się".
        'delete_grace_days' => 30,

        // Nazwy zastrzeżone dla obsługi serwisu.
        //
        // Konto o nazwie sugerującej Kuking („moderacja", „pomoc", „platnosci")
        // to gotowe narzędzie phishingu: wiadomość od @moderacja z prośbą
        // o hasło albo o „potwierdzenie płatności" wygląda wiarygodnie,
        // a nasza grupa jest na to wyjątkowo podatna.
        //
        // Lista jest TUTAJ, a nie w walidatorach, bo obowiązuje na dwóch
        // ścieżkach (rejestracja i zmiana nazwy w ustawieniach). Dopisanie
        // nazwy w jednym miejscu ma domykać obie naraz — inaczej powstaje
        // druga, cichsza furtka.
        //
        // Wpisy piszemy naturalnie, bez polskich znaków i bez wariantów
        // zapisu: App\Rules\ReservedUsername normalizuje TAK SAMO obie strony
        // porównania, więc „Moderacja", „m0deracja" i „m_o_d_e_r_a_c_j_a"
        // trafiają na ten sam wpis.
        'reserved_usernames' => [
            'admin',
            'administrator',
            'moderacja',
            'moderator',
            'kuking',
            'pomoc',
            'support',
            'obsluga',
            'kontakt',
            'zespol',
            'oficjalne',
            'redakcja',
            'bezpieczenstwo',
            'platnosci',
        ],
    ],

    'limits' => [
        // Limity zapytań (throttle) per akcja. Liczba prób na minutę.
        'login' => '5,1',
        'register' => '5,10',
        'password_reset' => '5,10',
        'upload' => '30,1',
        'comment' => '10,1',
        'post' => '20,10',
        'report' => '10,10',
        'search' => '60,1',
    ],

    'exports' => [
        // Paczka z danymi to kopia CAŁEGO konta — nie może leżeć na dysku
        // publicznym. Dysk `local` jest prywatny; pobranie idzie przez trasę
        // z podpisem, nie przez bezpośredni URL do pliku.
        'disk' => env('KUKING_EXPORT_DISK', 'local'),

        // Ile dni paczka jest do pobrania. Po tym czasie plik jest kasowany
        // (komenda kuking:sprzataj-eksporty) — nie trzymamy w storage kopii
        // konta bez końca.
        'ttl_days' => (int) env('KUKING_EXPORT_TTL_DAYS', 7),

        // Ile plików zdjęć wrzucamy do archiwum, zanim domkniemy je i otworzymy
        // od nowa. ZipArchive wymaga, żeby dodane pliki istniały do momentu
        // close(); ten próg ogranicza zużycie dysku tymczasowego przy koncie
        // z tysiącem zdjęć.
        'photo_flush_every' => 25,
    ],

    'community' => [
        // Adres, na który idą zgłoszenia i sprawy moderacyjne.
        'contact_email' => env('KUKING_CONTACT_EMAIL', 'kontakt@kuking.pl'),
    ],

    'wersja' => [
        // ETAP PRODUKTU — podbijany RĘCZNIE, przy kamieniach milowych
        // z docs/ROADMAP.md. Trzymany w repo, nie w zmiennej środowiskowej,
        // żeby zmiana wersji przechodziła przez recenzję jak każda inna.
        //
        // Numeracja: „Alfa 0.N" do czasu zamkniętej alfy (D-012), potem
        // „Beta 0.N", potem 1.0. Bez SemVera — nie wydajemy biblioteki,
        // której ktoś pilnuje zgodności API, tylko serwis dla ludzi.
        'etykieta' => 'Alfa 0.1',

        // CO DOKŁADNIE JEST WDROŻONE — ustawiane samo, przez Railway.
        // Ta sama wartość idzie do SENTRY_RELEASE (.railway/railway.ts), więc
        // wersja w stopce i wersja przy błędzie w Sentry to ten sam commit.
        'commit' => env('RAILWAY_GIT_COMMIT_SHA'),
    ],
];
