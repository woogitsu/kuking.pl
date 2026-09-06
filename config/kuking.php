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

        /*
         * Dysk PUBLICZNYCH WARIANTÓW — osobny od dysku oryginałów.
         *
         * Oryginał niesie pełny EXIF, czyli współrzędne GPS kuchni. Wariant
         * powstaje przez przekodowanie, więc EXIF-u już nie ma. To są dwie
         * różne kategorie danych i dlatego leżą w dwóch różnych bucketach:
         * na R2 publiczność jest cechą BUCKETU, nie obiektu, a `x-amz-acl`
         * jest tam wprost nieobsługiwany (patrz `config/filesystems.php`).
         *
         * Domyślnie `r2_publiczne`, gdy oryginały idą na `r2`. Na dysku
         * lokalnym (`public` w testach i przy pracy lokalnej) rozdział nie ma
         * sensu — tam nie ma CDN-u ani bucketów — więc oba wskazują to samo
         * i to jest w porządku. Sprawdza to `RozdzialMagazynowTest`, który
         * wymaga rozdziału tylko tam, gdzie dysk oryginałów jest sterownikiem
         * `s3`.
         */
        'public_disk' => env(
            'KUKING_MEDIA_PUBLIC_DISK',
            env('KUKING_MEDIA_DISK', env('FILESYSTEM_DISK', 'public')) === 'r2'
                ? 'r2_publiczne'
                : env('KUKING_MEDIA_DISK', env('FILESYSTEM_DISK', 'public')),
        ),

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

        // Formaty, które NAPRAWDĘ umiemy przetworzyć — nie te, które umiemy
        // rozpoznać. To dwie różne listy i pomylenie ich było błędem.
        //
        // BEZ HEIC/HEIF, ŚWIADOMIE. Były tu, bo `mime_content_type()` je
        // rozpoznaje. Ale rozpoznanie nie jest obsługą:
        //   * PHP 8.4 nie ma `IMAGETYPE_HEIC` ani `IMAGETYPE_HEIF`, więc
        //     `getimagesize()` w `StoreUploadedImage` zwraca dla nich `false`;
        //   * GD (`ImageManager::gd()` w `ProcessUploadedImage`) nie dekoduje
        //     HEIC — obraz `gd_info()` ma JPEG, PNG, WebP, AVIF i GIF, nic więcej.
        // Wpisanie ich tutaj nie dawało więc obsługi, tylko obietnicę: pole
        // wyboru pliku podpowiadało HEIC, a serwis odpowiadał „ten plik nie
        // wygląda na zdjęcie" komuś, kto trzyma w ręku zwykłą fotografię.
        //
        // Dodanie prawdziwej obsługi (libheif + Imagick albo vips w obrazie
        // Dockera) to osobna decyzja z realnym kosztem — patrz issue o HEIC.
        'accepted_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif',
        ],

        /*
         * Czyszczenie cache CDN po skasowaniu zdjęcia (audyt G-03).
         *
         * Cloudflare wprost ostrzega: przy włączonym cache na własnej domenie
         * skasowany obiekt R2 BYWA DALEJ SERWOWANY z cache aż do wygaśnięcia,
         * dopóki ktoś go stamtąd nie usunie. Dla miniatury to niedogodność.
         * Dla wymazania konta, żądania z RODO, decyzji moderacyjnej albo
         * zdjęcia wgranego przez pomyłkę to jest awaria prywatności: serwis
         * mówi „skasowane", a plik nadal się otwiera.
         *
         * Puste `zone_id` albo `token` = czyszczenie WYŁĄCZONE. Tak jest
         * lokalnie i w testach i to jest w porządku — nie ma tam CDN-u.
         * Ale wyłączenie jest GŁOŚNE: `PurgePublicMediaCache` zapisuje wtedy
         * ostrzeżenie w logu, bo cicha rezygnacja z czyszczenia wygląda
         * dokładnie tak samo jak czyszczenie, które działa.
         */
        'cdn_purge' => [
            'zone_id' => env('CLOUDFLARE_ZONE_ID'),
            'token' => env('CLOUDFLARE_PURGE_TOKEN'),
            'endpoint' => env(
                'CLOUDFLARE_PURGE_ENDPOINT',
                'https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache',
            ),
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

    'theme' => [
        // Jasny/ciemny wygląd — ustawiany na /ustawienia/czytelnosc i przez
        // szybki przełącznik w stopce (docs/DECISIONS.md, D-019). Wartości
        // muszą mieścić się w CHECK z migracji `..._add_theme_to_users`.
        //
        // Świadomie BEZ trzeciej wartości „jak w systemie" — patrz komentarz
        // w tej migracji. Dodanie jej przywróciłoby dokładnie to zachowanie
        // (motyw zmieniający się sam, bez pytania), które ta funkcja
        // ma wyłączyć.
        'options' => ['light', 'dark'],
        'default' => 'light',

        // Nazwa ciasteczka z wyborem GOŚCIA (bez konta). Zalogowany ma wybór
        // na koncie (kolumna `theme`) — cookie i tak dostaje tę samą wartość,
        // żeby wygląd nie mrugnął z powrotem do jasnego, gdyby ta sama osoba
        // wylogowała się na tym samym urządzeniu.
        'cookie' => 'motyw',
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

    'two_factor' => [
        // Nazwa serwisu pokazywana w aplikacji uwierzytelniającej (Google
        // Authenticator, Aegis…) obok konta — inaczej wpis w aplikacji
        // nazywałby się samym adresem e-mail i nie dałoby się go odróżnić
        // od innych kont TOTP na tym samym telefonie.
        'issuer' => 'Kuking',

        // Ile jednorazowych kodów zapasowych dostaje osoba przy włączeniu
        // 2FA. Pokazywane RAZ, do wydruku — bez nich zgubiony telefon
        // to utracone konto moderatora na zawsze.
        'recovery_codes' => 8,

        // Okno tolerancji: jeden krok WSTECZ i jeden W PRZÓD (czyli ±30 s
        // przy standardowym kroku 30 s) — zegar telefonu potrafi się rozjechać
        // o kilkadziesiąt sekund, ale szersze okno zwiększa szansę odgadnięcia
        // kodu. Przekazywane wprost do `Google2FA::verifyKeyNewer()`.
        'window' => 1,
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

        /*
         * Zgłoszenie nielegalnej treści (DSA art. 16) — droga PUBLICZNA.
         *
         * Limit jest tu jedyną ochroną przed nadużyciem, bo logowania nie ma
         * i być nie może: przepis wymaga mechanizmu dostępnego dla każdego,
         * a wymóg konta wyklucza dokładnie tych, dla których on istnieje —
         * prawnika, rodzica, osobę, która rozpoznała siebie na cudzym zdjęciu.
         *
         * Trzy na godzinę z jednego adresu: dość, żeby ktoś zgłosił kilka
         * rzeczy naraz i poprawił literówkę, za mało na zalanie kolejki
         * jedynego moderatora (D-012: zespół to 1-2 osoby).
         */
        'legal_notice' => '3,60',

        // Odwołanie od decyzji moderacyjnej. Limit jest niski, bo formularz
        // dla osób zablokowanych stoi PRZED logowaniem — a wszystko, co stoi
        // przed logowaniem, jest celem. Prawdziwe odwołanie składa się raz,
        // więc pięć prób na godzinę nikomu nie przeszkadza.
        'appeal' => '5,60',
        'search' => '60,1',
        // Odhaczanie kroku w trybie gotowania (issue #24). Zapisuje tylko
        // do sesji przeglądarki — bez ryzyka takiego jak przy komentarzu
        // czy zdjęciu — ale to i tak POST na cudzy (jeśli ktoś zgadnie
        // slug) przepis, więc limit stoi tu z tego samego powodu co reszta:
        // jeden formularz nie ma prawa zalać serwera. Wyżej niż `comment`,
        // bo klikanie „poprzedni/następny krok” w trakcie gotowania zdarza
        // się częściej niż pisanie komentarzy.
        'cooking_krok' => '60,1',

        // Weryfikacja kodu 2FA (logowanie i wyłączanie, issue #12). Kod ma
        // sześć cyfr — milion możliwości brzmi dużo, ale bez limitu prób to
        // pytanie o minuty, nie o bezpieczeństwo. Format „próby,minuty” jak
        // reszta tego pliku; blokada liczy się PO KONCIE (adres e-mail albo
        // nazwa użytkownika), nie po adresie IP — inaczej rozproszony atak
        // z wielu adresów obchodziłby limit, zostawiając samo konto bez
        // żadnej ochrony.
        'two_factor' => '5,1',
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
        /*
         * Paczka z danymi to kopia CAŁEGO konta — nie może leżeć na dysku
         * publicznym. Pobranie idzie przez trasę z podpisem, nie przez
         * bezpośredni URL do pliku.
         *
         * DYSK LOKALNY NIE WYSTARCZA NA PRODUKCJI (audyt W3-01).
         * `web`, `worker` i `scheduler` to trzy osobne kontenery bez wspólnego
         * wolumenu. Paczkę buduje worker, a pobranie obsługuje web — plik
         * powstawał więc w jednym kontenerze, a szukano go w drugim: w bazie
         * `ready`, a człowiek dostawał 404, i to w chwili, w której zwykle
         * właśnie zamyka konto. Restart workera zabierał ją tak samo.
         *
         * Dlatego domyślnie podążamy za `FILESYSTEM_DISK`: gdy zdjęcia idą na
         * R2, paczki idą na `r2_eksporty` — osobny, prywatny bucket, bez
         * własnej domeny. `local` zostaje wyłącznie tam, gdzie jest jeden
         * proces: lokalnie i w testach.
         */
        'disk' => env(
            'KUKING_EXPORT_DISK',
            env('FILESYSTEM_DISK', 'local') === 'r2' ? 'r2_eksporty' : 'local',
        ),

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
