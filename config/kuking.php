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
        //
        // UWAGA NA `docker/php.ini`: ta liczba, pomnożona przez
        // `max_per_post` niżej, MUSI z zapasem mieścić się w `post_max_size`
        // z `docker/php.ini` — inaczej PHP odrzuca całe żądanie (razem
        // z tokenem CSRF) jeszcze zanim Laravel zdąży cokolwiek zwalidować,
        // a człowiek dostaje "Page Expired" zamiast polskiego komunikatu
        // (audyt A31). Test `UploadLimitsAgreementTest` pilnuje tej zgody,
        // bo php.ini nie umie CZYTAĆ konfiguracji Laravela — dwa niezależne
        // miejsca to jedyny sposób, więc musi je pilnować test, nie wspólny
        // kod.
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

        // Maksymalna liczba zdjęć w JEDNEJ wysyłce (wpis albo „Ugotowałem").
        //
        // DLACZEGO SZEŚĆ, A NIE JEDNO
        // Audyt A31 zaproponował obniżenie do jednego zdjęcia, bo sześć razy
        // 15 MB nie mieściło się w `post_max_size`. Właściciel rozstrzygnął
        // inaczej: limit PHP jest do podniesienia, a część ludzi pokazuje
        // danie w kilku ujęciach — kolaż, karuzela, krok po kroku. Odebranie
        // im tego naprawiałoby rozjazd kosztem funkcji, o którą sami proszą.
        //
        // Ta liczba razy `max_bytes` MUSI z zapasem mieścić się
        // w `post_max_size` z `docker/php.ini` — pilnuje tego
        // `UploadLimitsAgreementTest`. Podniesienie tej liczby bez
        // podniesienia limitu PHP oblewa test, i o to chodzi.
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
        // Formularz cofnięcia usunięcia konta stoi PRZED logowaniem (audyt A8,
        // ten sam powód co limit 'appeal' dla formularza odwołań #10) — jest
        // celem do zgadywania haseł, więc 5 prób na godzinę, nie na minutę.
        'cancel_delete' => '5,60',
        // 'upload' USUNIĘTY, a nie poprawiony.
        //
        // Ten wpis nie był podpięty do żadnej trasy: wgrywanie zdjęć chodzi
        // w ramach `posts.store`, `cooked.store` i `recipes.store`, czyli pod
        // limitem `post`. Limit istniał, tylko inny — a w pliku stała liczba,
        // która wyglądała, jakby coś robiła, i nie robiła nic.
        //
        // Dokładnie ten sam kształt błędu co udokumentowany wyżej
        // `kuking.media_disk`. AGENTS.md §7 mówi, że ten plik jest jedynym
        // źródłem prawdy o limitach — martwy wpis jest tu gorszy niż jego brak,
        // bo następna osoba podniesie tę liczbę i uzna sprawę za załatwioną.
        'comment' => '10,1',
        'post' => '20,10',
        'report' => '10,10',

        // Odwołanie od decyzji moderacyjnej. Limit jest niski, bo formularz
        // dla osób zablokowanych stoi PRZED logowaniem — a wszystko, co stoi
        // przed logowaniem, jest celem. Prawdziwe odwołanie składa się raz,
        // więc pięć prób na godzinę nikomu nie przeszkadza.
        'appeal' => '5,60',
        'search' => '60,1',
        // Zgłoszenia naruszeń CSP wysyła sama przeglądarka. Jedna zapętlona
        // wtyczka potrafi wysłać setki na minutę, a każde to wpis w logu —
        // stąd limit wyraźnie wyższy niż przy formularzach, ale skończony.
        'csp_report' => '60,1',
        // Akcje w ustawieniach, które proszą o obecne hasło jako potwierdzenie
        // tożsamości (zmiana hasła, „wyloguj mnie z innych urządzeń", issue #12).
        // Ten sam rząd wielkości co 'password_reset' — to wciąż zgadywanie
        // cudzego hasła, tyle że przez kogoś, kto już ma cudzą sesję.
        'confirm_password' => '5,10',
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

    // STREFA, W KTÓREJ POKAZUJEMY CZAS — nie ta, w której go zapisujemy.
    //
    // `app.timezone` zostaje UTC i musi zostać: to jest strefa, w której
    // aplikacja liczy i pisze do bazy. Ta tutaj dotyczy wyłącznie tego,
    // co widzi człowiek (issue #87, pomocnik `App\Support\Czas`).
    'strefa' => env('KUKING_STREFA', 'Europe/Warsaw'),

    'community' => [
        // Adres, na który idą zgłoszenia i sprawy moderacyjne.
        'contact_email' => env('KUKING_CONTACT_EMAIL', 'kontakt@kuking.pl'),

        // GOSPODARZ — konto, które nowe osoby zaczynają obserwować przy
        // rejestracji (docs/product/COLD_START.md).
        //
        // Feed obserwowanych nowego konta jest z definicji pusty, a pusty
        // ekran dla kogoś po sześćdziesiątce znaczy „to nie jest dla mnie".
        // Gospodarz publikuje codziennie, więc jest jedyną treścią, na którą
        // można liczyć od pierwszej sekundy.
        //
        // To jest decyzja podjęta ZA CZŁOWIEKA, więc obowiązkowo z widoczną
        // możliwością cofnięcia — przycisk „Nie obserwuj" na profilu istnieje.
        // Pusta wartość wyłącza mechanizm całkowicie.
        //
        // Nazwa użytkownika, nie identyfikator: gospodarz może się zmienić,
        // a nazwa jest tym, co widać i co da się sprawdzić okiem.
        'host_username' => env('KUKING_HOST_USERNAME', 'woogitsu'),
    ],

    'moderation' => [
        // TERMINY ODWOŁANIA (issue #10, DSA art. 20).
        //
        // Te liczby nie są wymyślone tutaj — stoją w
        // `docs/legal/MODERATION_PLAYBOOK.md` (szablony 4.1-4.5 i §3
        // „Ścieżka odwołania") i są OBIETNICĄ złożoną użytkownikowi.
        // Dlatego mieszkają w konfiguracji, a nie w trzech miejscach w kodzie:
        // rozjazd między dokumentem a systemem jest gorszy niż brak obu.

        // Ile dni od decyzji można złożyć odwołanie. Playbook obiecuje 14
        // w każdym szablonie wiadomości do użytkownika.
        'appeal_days' => (int) env('KUKING_APPEAL_DAYS', 14),

        // Ile DNI ROBOCZYCH mamy na odpowiedź. Playbook §3 punkt 4.
        // Świąt nie liczymy — Carbon zna weekendy, nie kalendarz polskich
        // dni wolnych. Termin jest więc celem operacyjnym pokazywanym
        // moderatorowi, nie zobowiązaniem co do godziny.
        'appeal_response_working_days' => (int) env('KUKING_APPEAL_RESPONSE_DAYS', 7),

        // Ile godzin ten sam moderator musi odczekać, zanim PODTRZYMA własną
        // decyzję. Playbook §3: „jedna osoba nie powinna być jednocześnie
        // moderatorem i jedynym organem odwoławczym dla własnych decyzji —
        // jeśli to niemożliwe personalnie, przynajmniej odczekaj i spójrz na
        // sprawę drugi raz po czasie".
        //
        // COFNIĘCIE własnej decyzji nie czeka ani chwili — przyznanie się do
        // pomyłki jest dokładnie tym, po co odwołanie istnieje, a kazanie
        // komuś siedzieć dobę z ukrytą treścią „dla higieny procesu" szkodzi
        // tej osobie, nie procesowi.
        'appeal_self_uphold_hours' => (int) env('KUKING_APPEAL_SELF_UPHOLD_HOURS', 24),
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
