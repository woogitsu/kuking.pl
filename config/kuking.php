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

        /*
         * Ile minut żyje podpisany adres wariantu w buckecie (audyt W7-02).
         *
         * Adresem zdjęcia jest trasa aplikacji; `MediaController` sprawdza
         * Policy treści nadrzędnej i przekierowuje na adres podpisany kluczem
         * S3. Ta liczba jest oknem, w którym skopiowany adres jeszcze działa
         * — czyli ceną całego rozwiązania, i dlatego stoi tutaj, a nie
         * w kontrolerze.
         *
         * PIĘĆ MINUT, bo dwie wartości muszą tu zagrać naraz:
         *
         *   za krótko  wolne łącze nie zdąży pobrać dużego wariantu, a karta
         *              zostawiona otwarta na kwadrans przestaje pokazywać
         *              zdjęcia po odświeżeniu obrazków przez przeglądarkę;
         *   za długo   przełączenie przepisu na prywatny albo zablokowanie
         *              kogoś nie odcina dostępu przez ten cały czas.
         *
         * `max-age` odpowiedzi dla treści publicznej to POŁOWA tej liczby,
         * nie ona sama: przeglądarka cache'uje przekierowanie razem z już
         * podpisanym adresem, więc przy równych wartościach 302 wyjęte
         * z cache w ostatniej sekundzie okna prowadziłoby pod adres, który
         * właśnie wygasa. Szczegóły w `MediaController::sekundyCache()`.
         */
        'signed_url_minutes' => (int) env('KUKING_MEDIA_SIGNED_URL_MINUTES', 5),

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

    'comments' => [
        // Ile komentarzy GŁÓWNYCH (wątków) pokazuje strona wpisu i przepisu.
        //
        // Komentarze rosną z popularnością treści, bez górnej granicy — a do
        // 7 września 2026 `RecipeController::show()` i `PostController::show()`
        // ładowały je WSZYSTKIE przez `->load()`. Nie wyszło to w audycie
        // zapytań bez limitu (T20), bo tam szukano `->get()`, a to jest ten
        // sam kształt ryzyka pod inną nazwą.
        //
        // 12, tak jak zapisane wpisy w zeszycie — jeden krok „Pokaż więcej"
        // ma być wszędzie podobny, żeby człowiek wiedział, czego się
        // spodziewać (UX_50_PLUS.md: przewidywalność przed bogactwem).
        'page_size' => (int) env('KUKING_COMMENTS_PAGE_SIZE', 12),
    ],

    'collections' => [
        // Ile ZAPISANYCH WPISÓW pokazuje zeszyt na "stronę" (audyt
        // zewnętrzny T20). Zeszyt rośnie z użyciem serwisu — każde
        // kliknięcie "Zapisz" na cudzym wpisie dokłada tam jedną pozycję,
        // bez górnej granicy — więc `CollectionController::show()` MUSI
        // paginować, tak jak od początku robi to obok stojące `recipes()`.
        // Ta sama wartość co tam (12), żeby dwie sekcje tego samego ekranu
        // nie skakały o różne kroki.
        'saved_posts_page_size' => (int) env('KUKING_COLLECTION_SAVED_POSTS_PAGE_SIZE', 12),
    ],

    'tags' => [
        // Otwarte tagi użytkowników, zastępują Tematy (D-021,
        // docs/DECISIONS.md). Liczby stąd czyta WYŁĄCZNIE App\Support\LimityTagow
        // — patrz komentarz w tamtym pliku, dlaczego żadna z nich nie ma
        // prawa być wpisana wprost w kontrolerze, widoku ani akcji domenowej.

        // Minimum — TEN SAM próg, którego używa wyszukiwarka
        // (App\Domain\Search\SearchQuery::recipes()/people()).
        'min_length' => (int) env('KUKING_TAG_MIN_LENGTH', 2),

        'max_length' => (int) env('KUKING_TAG_MAX_LENGTH', 30),

        // Ile RÓŻNYCH tagów (po unikalnych tag_id, patrz LimityTagow) wolno
        // przypiąć do jednego wpisu. Dość, żeby oznaczyć danie, okazję
        // i dietę naraz; za mało, żeby stać się polem na słowa kluczowe SEO
        // wklejone hurtem.
        'max_per_post' => (int) env('KUKING_TAG_MAX_PER_POST', 5),

        // Ile podpowiedzi zwraca wyszukiwarka tagów (SPEC §1.5) — zarówno
        // ścieżka z JavaScriptem, jak i formularz „Znajdź tag" bez niego.
        'suggestions_limit' => (int) env('KUKING_TAG_SUGGESTIONS_LIMIT', 8),
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

        /*
         * ILE GODZIN ŻYJE ZAMÓWIONA ZMIANA ADRESU E-MAIL (issue #195).
         *
         * Po tym czasie link z listu przestaje działać, a wiersz
         * `pending_email_changes` kasuje `kuking:sprzataj-zmiany-adresu`.
         *
         * SKĄD 24 GODZINY, A NIE GODZINA JAK PRZY `verification.verify`.
         * Tamten link klika się w tej samej minucie, w której powstało konto
         * — człowiek siedzi przy komputerze i CZEKA na wiadomość. Ten idzie
         * na DRUGĄ skrzynkę, często na innym urządzeniu: na komórkę, którą
         * trzeba wziąć z kuchni, albo na skrzynkę, do której ta osoba
         * zagląda raz dziennie. Godzina znaczyłaby, że zmiana adresu udaje
         * się tylko tym, którzy mają obie skrzynki otwarte naraz — czyli
         * nie tym, dla których to zgłoszono (docs/UX_50_PLUS.md).
         *
         * DLACZEGO NIE WIĘCEJ. Przez cały ten czas ważny jest link, którym
         * ktoś, kto raz dorwał się do cudzej sesji, może dokończyć przejęcie
         * konta. Doba to jedna noc: właściciel zdąży zobaczyć list
         * ostrzegawczy na STARYM adresie i zmienić hasło — a zmiana hasła
         * kasuje oczekujące żądanie (`CancelEmailChange`).
         */
        'email_change_ttl_hours' => (int) env('KUKING_EMAIL_CHANGE_TTL_HOURS', 24),

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

        // Konta testowe/deweloperskie, wykluczone z metryk North Star
        // (issue #114 — `kuking:wac` i kohorta retencji z
        // `docs/seo/ANALYTICS.md` §2.2/§3.2).
        //
        // DLACZEGO KONFIGURACJA, A NIE KOLUMNA W BAZIE
        // Issue #114 rozstrzyga to wprost: żadna nowa kolumna na tym etapie.
        // Przy 20-50 kontach zamkniętej alfy konta testowe to garstka, którą
        // zna jedna osoba (właściciel) i która zmienia się rzadko — dokładnie
        // taki przypadek, dla którego reszta tego pliku istnieje (`comment`,
        // `zone_id`+`token` wyżej): próg/lista bez migracji, bez deployu drugi
        // raz, gdy trzeba dopisać jedno konto. Kolumna `users.is_test_account`
        // byłaby uzasadniona dopiero, gdyby test'owych kont było wiele albo
        // gdyby WIĘCEJ niż jedna metryka miała je wykluczać — a to jest
        // dokładnie sytuacja, w której warto ją dodać (razem z migracją,
        // testem i wpisem w `docs/DATABASE.md`, jak wymaga AGENTS.md §6).
        //
        // Nazwa użytkownika, tak jak `host_username` — to jest to, co widać
        // i co da się sprawdzić okiem na liście kont, nie wewnętrzny UUID.
        // Porównanie jest bez rozróżniania wielkości liter (jak
        // `Profile::poNazwie()`), bez homoglifów — to nie jest ochrona przed
        // podszywaniem się, tylko lista własnych kont, więc prostsze
        // porównanie wystarcza.
        //
        // Puste domyślnie: bez tej zmiennej środowiskowej metryka liczy
        // wszystkich tak jak dotąd (poza gospodarzem i kontami zbanowanymi/
        // kasowanymi).
        'test_usernames' => array_filter(explode(',', (string) env('KUKING_TEST_USERNAMES', ''))),
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

    /*
     * TRZY KOSZYKI LIMITERA LOGOWANIA (W7-01, R3 §5).
     *
     * Osobno od `limits` niżej, bo to nie są limity `throttle:` na trasie —
     * to trzy niezależne liczniki wewnątrz `LoginController`, każdy liczony
     * po innym kluczu (patrz `App\Support\KluczeLimitow`).
     *
     * DLACZEGO TRZY, A NIE JEDEN. Licznik przywiązany do ADRESU
     * strukturalnie nie widzi ataku rozproszonego po wielu adresach na jedno
     * konto — a zmiana adresu jest dla napastnika tania (botnet, sieć
     * mobilna, chmura), niezależnie od tego, czy `trustProxies` ufa
     * nagłówkowi. Zmierzone przed naprawą: 20 nieudanych prób na to samo
     * konto z 20 różnych adresów nie wywoływało żadnej blokady.
     *
     * SKĄD TE LICZBY:
     *
     *  - para konto+adres 5/1 min — dokładnie tyle, ile miał dotychczasowy
     *    limiter, czyli wartość już skalibrowana na tę grupę użytkowników;
     *    nikt się dotąd nie skarżył, że jest za ostra. Osoba, która pomyli
     *    hasło dwa-trzy razy i kliknie dwa razy, mieści się z zapasem. Piąta
     *    pomyłka w minucie z tego samego adresu na to samo konto to wzorzec
     *    bota, nie palca.
     *
     *  - konto 15/15 min — WYŻSZE niż suma kilku okien koszyka pary, i to
     *    jest celowe. Rolą tego koszyka nie jest chronić przed pomyłką
     *    człowieka, tylko przed atakiem, którego dwa pozostałe nie widzą.
     *    Osoba 50+ próbująca różnych starych haseł przez kwadrans mieści się
     *    w nim z zapasem.
     *
     *  - adres 100/5 min — dostatecznie wysoko, żeby nie karać biura,
     *    rodziny za jednym ruterem ani operatora komórkowego pod wspólnym
     *    NAT-em (typowe dla starszych użytkowników), i dostatecznie nisko,
     *    żeby złapać rozpylanie po wielu kontach z jednego miejsca.
     */
    'login_limits' => [
        'para' => ['proby' => 5, 'sekundy' => 60],
        'konto' => ['proby' => 15, 'sekundy' => 900],
        'adres' => ['proby' => 100, 'sekundy' => 300],
    ],

    'notifications' => [
        /*
         * Okno, w którym powiadomienie o STANIE nie wraca (R3 §7).
         *
         * Dotyczy wyłącznie rodzajów z `NotifyUser::TYPY_WYCISZANE_W_OKNIE`
         * — dziś samego „X Cię obserwuje". Komentarze i „Ugotowałem" to
         * ZDARZENIA i dochodzą zawsze, bo drugi komentarz jest nową rzeczą.
         *
         * Doba, nie godzina: chodzi o wzorzec rozłożony na godziny (ktoś
         * odobserwowuje i wraca, sprawdzając, czy dana osoba zniknie mu
         * z tablicy), a nie o spam w obrębie minuty — tamten łapie limit
         * liczby żądań. Po dobie powrót jest już nową informacją i druga
         * strona ma prawo o nim wiedzieć.
         */
        'okno_powtorzenia_godzin' => (int) env('KUKING_OKNO_POWTORZENIA_GODZIN', 24),

        // RETENCJA (issue #19, docs/decyzje/ADR_RETENCJE.md §5.2).
        //
        // DECYZJA WŁAŚCICIELA, 2026-09-07 (druga tura, po zewnętrznej ocenie
        // prawnej — `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §D/§6) —
        // 3 MIESIĄCE, NIE 24. Pierwsza wersja tego automatu (24 miesiące)
        // była rekomendacją agenta badawczego, nie oceną prawnika. Ocena
        // zewnętrzna nazwała ją wprost za długą: powiadomienie ma zwrócić
        // uwagę na zdarzenie, nie zastępować bezterminowego archiwum relacji
        // ani dokumentacji decyzji dostępnej do odwołania — ta ostatnia żyje
        // osobno, w `reports`/`moderation_actions`/`appeals`
        // (`moderation.case_retention_months` niżej), nie w tej tabeli.
        //
        // Jeden wiek dla WSZYSTKICH powiadomień liczony od `created_at`,
        // NIEZALEŻNIE od `read_at` (wariant A z ADR §6, nie B) — prostsze
        // i zgodne z minimalizacją danych wprost; wariant B (nieprzeczytane
        // nigdy nie wygasają) ryzykowałby bezterminowe trzymanie powiadomień,
        // których ktoś nigdy nie otworzy (czyli już nigdy nie otworzy).
        //
        // WYJĄTEK — KOLIZJA Z SZEŚCIOMIESIĘCZNYM TERMINEM ODWOŁANIA (DSA
        // ART. 20 UST. 1). Trzy miesiące są KRÓTSZE niż sześć miesięcy, w
        // które prawo do odwołania od decyzji moderacyjnej ma obowiązywać
        // (`ModerationAction::appealDeadline()`). Powiadomienie o decyzji
        // niesie jedyny w serwisie link „Odwołaj się"
        // (`app/Domain/Moderation/Actions/NotifyModerationDecision.php`,
        // `data.action_id`) — wygaszenie go po trzech miesiącach odbierałoby
        // prawo, które obowiązuje jeszcze trzy miesiące dłużej.
        //
        // Rozwiązanie jest tego samego kształtu co `AuditLogEntry::NIGDY_NIE_KASUJ`
        // (zamknięta stała w kodzie, nie w configu — zmiana ma przechodzić
        // przez code review, nie przez zmienną środowiskową): TYPY powiadomień
        // z `App\Models\Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`
        // NIE są kandydatem do usunięcia wg TEJ liczby — ich własny termin to
        // `ModerationAction::appealDeadline()`, wyliczony z powiązanej decyzji,
        // NIE druga, osobno wpisana liczba miesięcy (rozjechałaby się z
        // prawdziwym terminem przy pierwszej zmianie `appeal_days`).
        //
        // Egzekwuje `kuking:sprzataj-powiadomienia`
        // (`App\Domain\Compliance\PrzedawnionePowiadomienia`).
        'retention_months' => (int) env('KUKING_NOTIFICATIONS_RETENTION_MONTHS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Formularze — klucz wysłania (idempotencja)
    |--------------------------------------------------------------------------
    |
    | Jedno wysłanie formularza to jeden zapis. Formularz niesie ukryte pole
    | `klucz_wyslania`, a częściowy indeks UNIQUE w bazie odbija drugi zapis
    | z tym samym kluczem (D-027, `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`).
    |
    */

    'formularze' => [
        // WYŁĄCZNIK AWARYJNY MECHANIZMU KLUCZA WYSŁANIA (ADR §8.4, „Wyjście 1").
        //
        // Awaria, przed którą to chroni, jest wąska, ale najgorsza z możliwych
        // dla tego mechanizmu: gdyby klucz przestał przechodzić przez `old()`
        // poprawnie, drugie — POPRAWIONE — wysłanie zostałoby uznane za
        // duplikat pierwszego. Skutek dla człowieka: poprawiony wpis nie
        // powstaje, a serwis odsyła go do wpisu, którego nie ma.
        //
        // Po ustawieniu na `false` formularze renderują się BEZ ukrytego pola,
        // kolumna dostaje `NULL`, częściowy indeks takiego wiersza nie obejmuje
        // i serwis wraca dokładnie do zachowania sprzed D-027 — z duplikatami,
        // ale bez ryzyka zablokowanej wysyłki.
        //
        // To jest jedyna droga wycofania, która NIE wymaga wdrożenia migracji.
        // Dlatego jest zmienną środowiskową: na Railway zmiana wartości i
        // restart to minuty, a wdrożenie migracji — nie.
        //
        // UWAGA: dla zgłoszeń (`reports`) ten wyłącznik NIE cofa wszystkiego.
        // Drugi indeks tej tabeli — `reports_one_open_per_pair` (jedno otwarte
        // zgłoszenie na parę zgłaszający–treść) — nie zależy od niczego, co
        // wysyła formularz, więc jego wycofanie to osobna migracja (ADR §8.4).
        'klucz_wyslania_wlaczony' => (bool) env('KUKING_KLUCZ_WYSLANIA', true),
    ],

    'limits' => [
        // Limity zapytań (throttle) per akcja. Liczba prób na minutę.
        //
        // `login` ZOSTAJE jako pierwsza, najtańsza bramka przed kontrolerem
        // — ale prawdziwą ochronę niosą trzy koszyki z `login_limits` wyżej.
        // Ten wpis liczy się po `domain|ip` dla gościa, więc sam z siebie nie
        // widzi ataku rozproszonego po adresach.
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

        /*
         * „Napisz do nas" (`/napisz-do-nas`) — formularz kontaktu z operatorem.
         *
         * DROGA JEST OTWARTA TAKŻE DLA GOŚCIA i to jest decyzja, nie
         * przeoczenie: najczęstsza rzecz, którą ludzie mają nam do powiedzenia
         * na starcie, brzmi „nie mogę się zalogować" albo „nie udało mi się
         * założyć konta". Formularz za logowaniem wykluczałby dokładnie te
         * osoby, dla których istnieje. Ochroną jest więc ten limit, nie konto.
         *
         * SKĄD PIĘĆ NA GODZINĘ. Człowiek pisze taką wiadomość raz, a jeśli
         * o czymś zapomni — drugi raz. Pięć zostawia zapas na poprawianie
         * literówek w adresie e-mail i na dwie osoby za jednym łączem
         * (limit liczy się po adresie IP dla gościa), a nie starcza na
         * zalanie kolejki jedynej osoby, która to czyta (D-012).
         *
         * Wyżej niż `legal_notice` (3/60), bo tam każde zgłoszenie zakłada
         * sprawę z terminem odpowiedzi z DSA art. 16 i decyzją do wydania;
         * tutaj kosztem nadużycia jest wiersz w tabeli i jedno powiadomienie.
         * Niżej niż `report` (10/10), bo tamten stoi już ZA logowaniem.
         */
        'kontakt' => '5,60',

        // Odwołanie od decyzji moderacyjnej. Limit jest niski, bo formularz
        // dla osób zablokowanych stoi PRZED logowaniem — a wszystko, co stoi
        // przed logowaniem, jest celem. Prawdziwe odwołanie składa się raz,
        // więc pięć prób na godzinę nikomu nie przeszkadza.
        'appeal' => '5,60',
        'search' => '60,1',
        // Podpowiedzi tagów podczas pisania wpisu (SPEC §1.5). Ten sam rząd
        // wielkości co 'search' — to jest ten sam rodzaj zapytania
        // (trigramowe podobieństwo po kuking_normalize()), tylko na innej
        // tabeli, i ta sama osoba już i tak korzysta z jednego budżetu
        // zapytań na konto.
        'tag_suggest' => '60,1',

        /*
         * Serwowanie zdjęcia (audyt W7-02) — trasa `media.show`.
         *
         * Trasa jest PUBLICZNA i pyta bazę o rodziców zdjęcia przy każdym
         * żądaniu, więc bez limitu byłaby nową, tanią drogą do zalania
         * serwisu (i do zgadywania, choć samo zgadywanie UUID-a jest
         * beznadziejne).
         *
         * DLACZEGO TAK WYSOKO NA TLE RESZTY TEGO PLIKU
         * Bo to nie jest formularz, tylko zasób strony. Jedna strona feedu
         * to do 20 wpisów po `max_per_post` = 6 zdjęć, plus awatary — czyli
         * grubo ponad sto żądań na JEDNO otwarcie strony, wysłanych
         * równolegle. Limit rzędu kilkudziesięciu na minutę wylogowywałby
         * zdjęcia zwykłemu czytelnikowi po przewinięciu dwóch ekranów, a to
         * wygląda dokładnie jak awaria serwisu.
         *
         * Liczy się PO ADRESIE IP dla gości (tak działa `throttle`), więc
         * musi pomieścić też kilka osób za jednym łączem.
         */
        'zdjecie' => '600,1',
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
        // tożsamości (zmiana hasła, „wyloguj mnie z innych urządzeń", issue #12,
        // a od BRAMKA_BETY §7a także wyłączenie 2FA i zgłoszenie usunięcia konta
        // — obie robią `Hash::check()` na haśle z formularza, czyli są tą samą
        // wyrocznią co zmiana hasła i nie mają prawa mieć luźniejszego limitu).
        // Ten sam rząd wielkości co 'password_reset' — to wciąż zgadywanie
        // cudzego hasła, tyle że przez kogoś, kto już ma cudzą sesję.
        'confirm_password' => '5,10',

        // Ponowna wysyłka listu potwierdzającego adres e-mail. Liczba
        // przeniesiona TUTAJ z `routes/web.php`, gdzie stała wpisana wprost
        // (`throttle:6,1,verification_resend`) wbrew AGENTS.md §7 — wartość
        // bez zmian, zmieniło się tylko miejsce, w którym się ją czyta.
        // Sześć na minutę: człowiek klika „wyślij jeszcze raz", nie widzi
        // listu (poczta potrafi iść minutę), klika znowu. Każde kliknięcie
        // to jeden e-mail z naszej puli.
        'verification_resend' => '6,1',

        /*
         |----------------------------------------------------------------
         | GRUPY LIMITÓW DLA TRAS ZAPISUJĄCYCH (BRAMKA_BETY §7a)
         |----------------------------------------------------------------
         |
         | Do tej pory limit miały głównie trasy „wejściowe" (logowanie,
         | rejestracja, reset hasła) oraz publikacja i komentarz. Reszta tras
         | zmieniających stan nie miała żadnego — wbrew AGENTS.md §7, gdzie
         | „rate limit" jest jednym z pięciu pytań przy KAŻDYM endpoincie.
         |
         | Klucze niżej są pogrupowane WEDŁUG SZKODY, jaką robi nadużycie,
         | a nie według kontrolera. Trasa, której nadużycie budzi telefon
         | drugiego człowieka, dostaje inny próg niż trasa, której nadużycie
         | najwyżej zaśmieci własny zeszyt sprawcy.
         |
         | KAŻDA LICZBA JEST GÓRNYM PUŁAPEM, NIE NORMĄ. Dobrane tak, żeby
         | osoba 50+ pracująca najszybciej, jak jej się w praktyce zdarza,
         | nie miała szans ich dotknąć — limit, który łapie zwykłego
         | użytkownika, jest gorszy niż jego brak, bo uczy go, że serwis się
         | psuje. Format „próby,minuty" jak reszta tego pliku.
         |
         | KILKA KLUCZY MA CELOWO TĘ SAMĄ LICZBĘ, ale osobną nazwę — i to
         | nie jest powtórzenie do posprzątania. Nazwa klucza jest zarazem
         | PREFIKSEM LICZNIKA w `throttle:<ile>,<minut>,<prefiks>`, więc dwa
         | osobne klucze to dwa osobne wiadra. Gdyby zwinąć je w jeden,
         | wróciłby błąd naprawiony w `ef4f6ca` („429 przy pierwszym
         | zdjęciu"): przeglądanie zjadałoby budżet publikowania.
         | Odwrotność — jeden prefiks na dwie różne liczby — jest błędem
         | i pilnuje jej `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`.
         */

        /*
         * USUWANIE WŁASNEJ TREŚCI — wpis, przepis, „Ugotowałem", zeszyt.
         *
         * Szkoda z nadużycia: treść znika innym ludziom sprzed oczu, a przy
         * przepisie z komentarzami i „Ugotowałem" kasowanie ciągnie za sobą
         * kaskadę pracy bazy. Odwrócić tego użytkownik sam nie umie.
         *
         * SKĄD 30 NA 10 MINUT. Każda akcja destrukcyjna w tym serwisie
         * wymaga potwierdzenia (AGENTS.md §5), więc jedno usunięcie to dwa
         * kliknięcia i chwila zastanowienia. Nawet porządki w profilu przed
         * betą — „skasuję te stare, nieudane zdjęcia" — to kilkanaście
         * pozycji, nie trzydzieści. Jedno usunięcie co dwadzieścia sekund
         * przez dziesięć minut bez przerwy to już nie palec, tylko pętla.
         */
        'usuwanie' => '30,10',

        /*
         * OBSERWOWANIE I ODOBSERWOWANIE — osoby oraz tagi.
         *
         * Szkoda z nadużycia: obserwowanie CZŁOWIEKA budzi powiadomienie
         * u drugiej strony. To jedyna grupa z tej listy, która sięga poza
         * konto sprawcy.
         *
         * JEDEN WSPÓLNY LICZNIK NA „OBSERWUJ" I „NIE OBSERWUJ" jest tu
         * celowy: gdyby każdy kierunek miał własny, cykl
         * obserwuj→przestań→obserwuj mieściłby się w podwójnym budżecie,
         * czyli dokładnie wzorzec, przed którym limit ma chronić.
         *
         * TAGI DZIELĄ TEN SAM KOSZYK, mimo że tag nie jest człowiekiem
         * i nikogo nie powiadamia. Powód: to ten sam ruch ręką i ten sam
         * rząd wielkości, a próg ustawia zawsze groźniejsza połowa grupy.
         *
         * SKĄD 60 NA 10 MINUT. Pierwszego dnia człowiek przechodzi przez
         * „Odkryj" i klika „Obserwuj" przy każdej osobie, która wygląda
         * ciekawie — to potrafi być kilkadziesiąt kliknięć w kilka minut
         * i MA prawo się udać, bo od tego zależy, czy jego strona główna
         * nie będzie pusta (AGENTS.md §8). Sześćdziesiąt zmian w dziesięć
         * minut mieści ten scenariusz z zapasem, a skrypt i tak potrzebuje
         * tysięcy, żeby cokolwiek na tym ugrać.
         *
         * Osobno działa druga bariera, po stronie powiadomień:
         * `notifications.okno_powtorzenia_godzin` (24 h) wycisza powtórkę
         * „X Cię obserwuje", więc cykliczne obserwowanie nie zamienia się
         * w serię powiadomień nawet w granicach tego limitu.
         */
        'obserwowanie' => '60,10',

        /*
         * MASOWE OBSERWOWANIE Z EKRANU POWITALNEGO (`/witaj/ludzie`).
         *
         * Osobny, znacznie niższy klucz niż `obserwowanie` wyżej, bo to
         * JEDYNA trasa w serwisie, na której POJEDYNCZE żądanie tworzy
         * WIELE powiadomień naraz — formularz wysyła listę kont. Wliczenie
         * jej do wspólnego koszyka byłoby pozorną ochroną: jedno żądanie
         * zabierałoby jeden punkt z sześćdziesięciu, robiąc pracę za kilka
         * tysięcy.
         *
         * SKĄD 5 NA 10 MINUT. Przez powitanie przechodzi się raz. Pięć
         * wysyłek tego jednego formularza mieści cofnięcie się w przeglądarce,
         * poprawkę wyboru i podwójne kliknięcie „Dalej".
         *
         * Sam ROZMIAR listy ogranicza osobno walidacja w `OnboardingController`
         * — limit żądań nie zastępuje limitu długości tablicy, tylko go
         * uzupełnia.
         */
        'masowe_obserwowanie' => '5,10',

        /*
         * BLOKOWANIE I ODBLOKOWANIE OSOBY.
         *
         * WŁASNY KOSZYK, ODDZIELONY OD `obserwowanie`, I TO JEST CAŁY POWÓD
         * ISTNIENIA TEGO KLUCZA. Blokada to narzędzie bezpieczeństwa: sięga
         * po nie ktoś, komu ktoś inny właśnie uprzykrza życie. Gdyby dzieliła
         * wiadro z obserwowaniem, człowiek, który rano naklikał się
         * w „Odkryj", po południu nie mógłby się zasłonić. Limit nie ma
         * prawa stanąć na drodze tej akcji.
         *
         * SKĄD 60 NA 10 MINUT. Sześćdziesiąt blokad w dziesięć minut
         * przekracza każdy realny scenariusz nękania obsługiwany blokowaniem
         * (przy takiej skali właściwą drogą jest „Zgłoś", nie klikanie),
         * a jednocześnie zamyka pętlę blokuj→odblokuj, która przy każdym
         * obrocie kasuje obserwowanie w obie strony i przepisuje wiersze.
         */
        'blokada' => '60,10',

        /*
         * ZESZYT — zapis i wypisanie przepisu albo wpisu, założenie zeszytu.
         *
         * Szkoda z nadużycia: praktycznie żadna poza kontem sprawcy. Nikt
         * inny tego nie widzi, nikogo to nie powiadamia, a odwrócenie to
         * jedno kliknięcie. Limit jest tu wyłącznie po to, żeby pojedyncze
         * konto nie potrafiło w pętli dopisywać wierszy bez końca.
         *
         * SKĄD 60 NA 10 MINUT. To najczęściej powtarzana uczciwa akcja
         * w całym produkcie: człowiek przegląda „Odkryj" i zapisuje wszystko,
         * co wygląda na niedzielny obiad. Dwadzieścia–trzydzieści zapisów
         * w kilka minut to normalny wieczór, więc próg musi stać wyraźnie
         * wyżej niż on.
         *
         * UWAGA — `collections.save-post` i `collections.unsave-post` były
         * do tej pory pod prefiksem `post`, czyli pod budżetem PUBLIKACJI.
         * Skutek był dokładnie taki jak w zgłoszeniu „429 przy pierwszym
         * zdjęciu": wieczór spędzony na zapisywaniu cudzych wpisów odbierał
         * prawo do opublikowania własnego. Przeniesione tutaj, do grupy,
         * do której należą.
         */
        'zeszyt' => '60,10',

        /*
         * USTAWIENIA PRYWATNE I DROBNE PRZEŁĄCZNIKI — czytelność,
         * prywatność, „Twoje tagi", wygląd jasny/ciemny, oznaczenie
         * powiadomień jako przeczytane, ukrycie wspomnienia, krok
         * „co lubisz gotować" z powitania.
         *
         * Szkoda z nadużycia: żadna widoczna dla innych. Jeden zapis to
         * jeden UPDATE na własnym wierszu.
         *
         * SKĄD 30 NA 10 MINUT. Najbardziej „klikany" ekran tej grupy to
         * czytelność: człowiek podnosi rozmiar tekstu, zapisuje, patrzy,
         * podnosi jeszcze raz. Sześć–osiem zapisów pod rząd to realne
         * maksimum takiej sesji; trzydzieści daje czterokrotny zapas.
         *
         * `/motyw` jest w tej grupie, mimo że jako jedyna z niej działa
         * TAKŻE dla gościa (D-019) — dla niezalogowanego licznik idzie po
         * adresie IP, więc próg musi pomieścić kilka osób za jednym łączem.
         * Trzydzieści przełączeń wyglądu na dziesięć minut z jednego adresu
         * mieści i to.
         */
        'ustawienia' => '30,10',

        /*
         * POWIADOMIENIA — kliknięcie „Zobacz" przy pojedynczym powiadomieniu.
         *
         * Szkoda z nadużycia: żadna. Jeden UPDATE znacznika `read_at` na
         * WŁASNYM wierszu, nikogo nie powiadamia, niczego nie tworzy.
         *
         * DLACZEGO OSOBNA GRUPA, A NIE `ustawienia`. Bo trzydzieści na
         * dziesięć minut jest tu za mało i to jest przewidywalne: człowiek,
         * który nie zaglądał tydzień, ma listę na dwie strony i przechodzi
         * ją po kolei. Trzydziesty pierwszy klik zwracałby 429 przy czynności
         * tak niewinnej jak czytanie własnych powiadomień — a to dokładnie
         * ten kształt błędu, który opisuje uwaga przy `zeszyt` wyżej.
         *
         * SKĄD 120 NA 10 MINUT. Strona mieści 30 powiadomień; cztery pełne
         * strony przeklikane w kwadrans to górna granica tego, co człowiek
         * zdąży zrobić ręcznie, i wciąż zostaje zapas.
         */
        'powiadomienia' => '120,10',

        /*
         * ZAPIS PROFILU (`PUT /ustawienia/profil`) — OSOBNO OD `ustawienia`.
         *
         * Bo to jedyny ekran ustawień, który przyjmuje PLIK. Zdjęcie
         * profilowe przechodzi przez cały pipeline z AGENTS.md §7:
         * sprawdzenie magic bytes, limit megapikseli, zapis do R2 i zadanie
         * w tle, które dekoduje obraz od nowa. To jest praca serwera liczona
         * w sekundach procesora i megabajtach, a nie UPDATE jednej kolumny —
         * i dlatego nie może dzielić wiadra z przełącznikiem wyglądu.
         *
         * SKĄD 15 NA 10 MINUT. Zmiana zdjęcia to wybranie pliku z telefonu,
         * czekanie na wysyłkę i obejrzenie wyniku. Trzy–cztery podejścia to
         * uparta sesja, piętnaście to już zdecydowanie za dużo jak na jeden
         * profil — a skrypt wgrywający obrazy potrzebowałby setek.
         */
        'ustawienia_profil' => '15,10',

        /*
         * PACZKA Z DANYMI (RODO) — `POST /ustawienia/twoje-dane/eksport`.
         *
         * Najdroższe pojedyncze żądanie w serwisie: kolejkuje zadanie, które
         * czyta całe konto i pakuje do archiwum wszystkie zdjęcia
         * (`exports.photo_flush_every` istnieje właśnie dlatego, że przy
         * koncie z tysiącem zdjęć nie mieści się to w pamięci).
         *
         * SKĄD 10 NA GODZINĘ, CZYLI POZORNIE DUŻO. Bo prawdziwą bramką jest
         * tu kontroler: `DataSettingsController::requestExport()` odrzuca
         * kolejne żądanie, dopóki poprzednia paczka się robi. Z tych
         * dziesięciu żądań pracą stanie się CO NAJWYŻEJ JEDNO. Limit ma
         * łapać tylko pętlę, a nie zniecierpliwione klikanie „Przygotuj
         * paczkę" przez człowieka, który nie widzi postępu — a takie
         * klikanie jest przy piętnastominutowym oczekiwaniu normą.
         *
         * Godzinne okno zamiast dziesięciominutowego, bo samo zadanie trwa
         * kilkanaście minut: krótsze okno nie opisywałoby niczego sensownego.
         */
        'eksport' => '10,60',

        /*
         * PANEL MODERACJI — decyzje o zgłoszeniach, przywracanie treści,
         * odwołania, odpowiedzi na wpisy bez odpowiedzi, tablica dnia,
         * tagi promowane.
         *
         * Szkoda z nadużycia: nie „spam", tylko przejęta sesja moderatora
         * użyta maszynowo — masowe usunięcie treści albo masowe przywrócenie
         * tego, co słusznie zniknęło.
         *
         * SKĄD AŻ 120 NA 10 MINUT, CZYLI NAJWYŻSZY PRÓG NA TEJ LIŚCIE.
         * Bo tu koszt fałszywego alarmu jest największy w całym pliku.
         * Zespół moderacji to jedna–dwie osoby (D-012). Fala spamu oznacza
         * kolejkę oczywistych zgłoszeń rozstrzyganych jedno po drugim,
         * seriami. Limit, który zatrzyma JEDYNEGO moderatora w środku takiej
         * fali, jest awarią serwisu, którą sami sobie zafundowaliśmy —
         * gorszą niż brak limitu. Dwanaście decyzji na minutę utrzymywane
         * przez dziesięć minut to około dwukrotność najszybszego realnego
         * tempa człowieka, więc pułap zostaje, ale nikt go nie dotknie.
         *
         * Dostęp do tych tras chronią przede wszystkim trzy warstwy stojące
         * przed limitem: `auth`, `moderator` i obowiązkowe 2FA moderatora
         * (#12). Ten limit jest ostatnim, nie pierwszym zabezpieczeniem.
         */
        'moderacja' => '120,10',
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

    'analytics' => [
        // Ile dni trzymamy wiersze `product_signals` (issue #115), zanim
        // komenda `kuking:sprzataj-sygnaly` je skasuje. To są zdarzenia
        // techniczne (nieudane wgranie zdjęcia, wykonane wyszukiwanie),
        // przydatne do wykrywania trendu w ostatnich tygodniach — nie mamy
        // powodu trzymać ich bezterminowo, a minimalizacja danych (AGENTS.md
        // §7) jest zasadą domyślną, nie wyjątkiem od niej.
        'signal_retention_days' => (int) env('KUKING_SIGNAL_RETENTION_DAYS', 90),

        // Najkrótszy odstęp między dwoma zapisami `users.ostatnio_widziany_at`
        // dla TEJ SAMEJ osoby (issue #114/#115, bramka V1 z `docs/ROADMAP.md`).
        //
        // DLACZEGO 15, A NIE PRZY KAŻDYM ŻĄDANIU
        // Znacznik ma odpowiedzieć na pytanie liczone w DNIACH i TYGODNIACH
        // (WAC, powrót po 7/30 dniach) — dokładność co do minuty nie zmienia
        // odpowiedzi na to pytanie, a zapis przy każdym żądaniu zmieniałby
        // JEDNĄ kolumnę `UPDATE`-em przy KAŻDYM żądaniu każdej zalogowanej
        // osoby — a jedna wizyta na stronie przepisu to więcej niż jedno
        // żądanie: dochodzi do tego każdy wariant zdjęcia (`docs/legal
        // /BRAMKA_BETY.md` §7 mierzy to wprost — 25 żądań wariantów zdjęć
        // z jednego otwarcia strony). Bez throttla jedna osoba przewijająca
        // feed generowałaby dziesiątki zbędnych zapisów na minutę.
        //
        // DLACZEGO AKURAT „KILKANAŚCIE" MINUT, NIE NP. GODZINA
        // 15 minut jest krótsze niż najkrótsza jednostka, w której raport
        // cokolwiek pokazuje (dzień), więc nie wprowadza żadnego
        // dostrzegalnego błędu w WAC/D7/D30 — a jednocześnie jest na tyle
        // długie, że aktywna sesja przeglądania (feed, przepis, kilka
        // zdjęć) generuje NAJWYŻEJ jeden zapis, nie jeden na każde kliknięcie.
        'last_seen_throttle_minutes' => (int) env('KUKING_LAST_SEEN_THROTTLE_MINUTES', 15),
    ],

    'audit_log' => [
        // RETENCJA `audit_log` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.1).
        //
        // DECYZJA WŁAŚCICIELA, 2026-09-07 (druga tura, po zewnętrznej ocenie
        // prawnej — `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §D/§6) —
        // 12 MIESIĘCY, NIE 24. Pierwsza wersja tego automatu (24 miesiące)
        // była rekomendacją agenta badawczego, nie oceną prawnika, i ocena
        // zewnętrzna nazwała ją wprost nieuzasadnioną: brakuje powodu, żeby
        // trzymać KAŻDE zdarzenie dwa lata, skoro konkretny dowód konkretnego
        // sporu i tak trafia do osobnej, dłużej trzymanej dokumentacji sprawy
        // (`moderation.case_retention_months` niżej) — 12 miesięcy wystarcza
        // na przegląd uprawnień, istotne zmiany i odtworzenie niedawnego
        // incydentu. Krócej niż okres spraw moderacyjnych z tego samego
        // powodu co wcześniej: pełny, autorytatywny dowód decyzji żyje w
        // `reports`/`moderation_actions`/`appeals` — wpis w `audit_log`
        // o tych samych zdarzeniach (`moderation.decided`, `content.reported`,
        // `appeal.*`) jest cieńszą kopią, która może wygasnąć wcześniej bez
        // utraty dowodu. Dłużej niż `product_signals`, bo `audit_log`
        // z definicji dokumentuje "zmiany wysokiego znaczenia"
        // (`docs/DATABASE.md`), nie czystą telemetrię.
        //
        // Egzekwuje `kuking:sprzataj-audyt`
        // (`App\Domain\Compliance\PrzedawnioneWpisyAudytu`).
        //
        // KATEGORIE, KTÓRYCH TA RETENCJA NIGDY NIE RUSZA, NIEZALEŻNIE OD WIEKU,
        // NIE SĄ TUTAJ — celowo. Lista jest zamkniętą stałą w kodzie
        // (`App\Models\AuditLogEntry::NIGDY_NIE_KASUJ`), z uzasadnieniem dla
        // każdej pozycji przy samej stałej. Trzymanie jej w configu (a więc
        // w zmiennej środowiskowej albo pliku edytowalnym bez code review)
        // otwierałoby drogę do wykasowania dowodu wykonania RODO/DSA jedną
        // zmianą wdrożeniową bez recenzji kodu — dokładnie tego ta lista ma
        // nie dopuścić.
        'retention_months' => (int) env('KUKING_AUDIT_LOG_RETENTION_MONTHS', 12),
    ],

    // STREFA, W KTÓREJ POKAZUJEMY CZAS — nie ta, w której go zapisujemy.
    //
    // `app.timezone` zostaje UTC i musi zostać: to jest strefa, w której
    // aplikacja liczy i pisze do bazy. Ta tutaj dotyczy wyłącznie tego,
    // co widzi człowiek (issue #87, pomocnik `App\Support\Czas`).
    'strefa' => env('KUKING_STREFA', 'Europe/Warsaw'),

    /*
     * KTO PROWADZI SERWIS — dane podmiotu, nie osoby prywatnej.
     *
     * Stoją TUTAJ, a nie tylko w dokumentach, z jednego powodu:
     * `DokumentyPrawneNieKlamiaTest` porównuje z nimi treść regulaminu
     * i polityki prywatności. Poprawka w jednym miejscu bez drugiego zapala
     * test na czerwono, zamiast po cichu zostawić na żywej stronie
     * nieaktualny numer KRS.
     *
     * DLACZEGO TO NIE MOGŁO ZOSTAĆ „NA PÓŹNIEJ". RODO art. 13 ust. 1 lit. a
     * wymaga podania tożsamości administratora W MOMENCIE zbierania danych,
     * czyli przy rejestracji — nie na żądanie i nie po otwarciu serwisu.
     * Do 8 września 2026 oba dokumenty mówiły „serwis prowadzi osoba
     * fizyczna" i obiecywały dane później. To było zaniechanie, nie
     * uproszczenie, i blokowało otwarcie rejestracji.
     */
    'podmiot' => [
        'nazwa' => 'SAMSUFI sp. z o.o.',
        'nazwa_pelna' => 'SAMSUFI Spółka z ograniczoną odpowiedzialnością',
        'ulica' => 'Jagiellońska 4A',
        'kod_pocztowy' => '19-120',
        'miejscowosc' => 'Knyszyn',
        'kraj' => 'Polska',
        'krs' => '0000901262',
        'nip' => '5423435334',
        'regon' => '388971059',

        /*
         * Adres, pod którym odpowiada spółka — i to NIE jest to samo, co
         * `community.contact_email` niżej.
         *
         * Tamten jest adresem serwisu i zależy od poczty na domenie
         * kuking.pl, której 8 września jeszcze nie ma (`MAIL_MAILER=log`).
         * Ten jest adresem spółki i działa niezależnie od niej. W dokumencie
         * prawnym musi stać adres, o którym wiadomo, że ktoś go czyta —
         * inaczej „napisz do nas" jest obietnicą bez pokrycia.
         */
        'email' => 'biuro@samsufi.pl',
    ],

    /*
    |--------------------------------------------------------------------------
    | „Napisz do nas" — wiadomości do operatora
    |--------------------------------------------------------------------------
    |
    | Formularz `/napisz-do-nas` i tabela `contact_messages`. Limit zapytań
    | stoi wyżej, przy `limits.kontakt` — tutaj jest tylko retencja.
    |
    */

    'kontakt' => [
        /*
         * RETENCJA WIADOMOŚCI — LICZONA OD ZAŁATWIENIA, NIE OD NAPISANIA.
         *
         * Wiadomość niesie dane osobowe: adres e-mail podany przez gościa
         * i zdanie napisane własnymi słowami, w którym potrafi się znaleźć
         * dosłownie wszystko („nie mogę się zalogować, moja żona ma to samo
         * nazwisko"). RODO każe trzymać je tylko tak długo, jak są potrzebne.
         *
         * DWANAŚCIE MIESIĘCY, a nie 36 jak sprawy moderacyjne: tam długi
         * okres broni się tym, że sprawa może wrócić jako spór prawny
         * (art. 442¹ k.c., ADR_RETENCJE.md §5.6). Tutaj nie ma decyzji, od
         * której da się odwołać, i nie ma sporu, do którego można by wrócić —
         * została wyłącznie wartość praktyczna: „czy ten sam błąd zgłaszał
         * już ktoś przed rokiem". Rok wystarcza, żeby to sprawdzić, i mieści
         * pełen cykl sezonów kulinarnych, w którym te same rzeczy wracają.
         *
         * Nie 3 miesiące jak powiadomienia: pomysł zgłoszony w marcu bywa
         * wdrażany jesienią, a wtedy trzeba wiedzieć, komu odpisać, że
         * jednak zrobione.
         *
         * WIADOMOŚĆ NIEZAŁATWIONA NIE JEST KASOWANA NIGDY, niezależnie od
         * wieku — tak samo jak otwarta sprawa moderacyjna. Kasowanie tego,
         * czego nikt nie przeczytał, zamieniłoby retencję w sprzątanie
         * dowodów zaniedbania.
         */
        'retention_months' => (int) env('KUKING_CONTACT_RETENTION_MONTHS', 12),
    ],

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

        // IMIĘ GOSPODARZA — podpis, który czyta CZŁOWIEK, nie konto.
        //
        // Pytanie było otwarte od początku projektu (`docs/brand/
        // COPY_STYLE.md` §8 „Zostały" — „Imię gospodarza w e-mailach").
        // Decyzja właściciela: gospodarzem jest **Ula** (patrz
        // `docs/DECISIONS.md` — wpis o imieniu gospodarza).
        //
        // TO NIE JEST TO SAMO CO `host_username` WYŻEJ. `host_username` to
        // nazwa konta, którą czyta MECHANIZM (auto-obserwowanie przy
        // rejestracji, `RegisterController::zaobserwujGospodarza()`) i która
        // musi dać się znaleźć w bazie (`Profile::where('username', ...)`).
        // `host_name` to imię, którym serwis PODPISUJE się przed człowiekiem
        // — nadawca maila (`docs/brand/COPY_STYLE.md` §6 „nadawca",
        // `docs/product/RETENTION_LOOPS.md` §4 „imię gospodarza + „z
        // Kuking""), a w przyszłości też tygodniowy digest i inne teksty
        // od gospodarza.
        //
        // JEDNO MIEJSCE, NIE WKLEJONE W KAŻDYM SZABLONIE: gospodarz może się
        // zmienić (odejście, zastępstwo) i wtedy to jest jedyna linijka do
        // poprawienia — bez przeszukiwania maili i widoków pod ręką.
        'host_name' => env('KUKING_HOST_NAME', 'Ula'),
    ],

    'moderation' => [
        // TERMINY ODWOŁANIA (issue #10, DSA art. 20).
        //
        // Te liczby nie są wymyślone tutaj — stoją w
        // `docs/legal/MODERATION_PLAYBOOK.md` (szablony 4.1-4.5 i §3
        // „Ścieżka odwołania") i są OBIETNICĄ złożoną użytkownikowi.
        // Dlatego mieszkają w konfiguracji, a nie w trzech miejscach w kodzie:
        // rozjazd między dokumentem a systemem jest gorszy niż brak obu.

        // Ile dni od decyzji można złożyć odwołanie.
        //
        // 180 DNI, NIE 14 — I TO NIE JEST PREFERENCJA.
        // Art. 20 ust. 1 DSA wymaga, żeby wewnętrzny system rozpatrywania
        // skarg był dostępny przez CO NAJMNIEJ SZEŚĆ MIESIĘCY od decyzji.
        // Do 7 września 2026 stało tu 14 dni — liczba przepisana
        // z `docs/legal/MODERATION_PLAYBOOK.md`, gdzie wzięła się z rozsądku
        // operacyjnego, nie z przepisu (pomiar: `docs/decyzje/DSA_POMIAR.md`,
        // sekcja o art. 20). Przy 14 dniach człowiek, który wrócił do serwisu
        // po miesiącu, nie miał już czego kliknąć.
        //
        // 180, a nie „6 miesięcy" liczone kalendarzowo: termin ma być
        // policzalny w dniach, bo tak jest pokazywany („Ten termin minął
        // 3 marca 2027"), a 180 dni jest KRÓTSZE niż sześć miesięcy w każdym
        // wariancie kalendarza — dlatego regulamin mówi „6 miesięcy", a kod
        // liczy z zapasem w drugą stronę: `appealDeadline()` dodaje 6
        // miesięcy kalendarzowych, a ta liczba jest tylko dolną granicą,
        // której nie wolno zejść poniżej (pilnuje jej test).
        'appeal_days' => (int) env('KUKING_APPEAL_DAYS', 180),

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

        // RETENCJA SPRAWY MODERACYJNEJ — `reports` + `moderation_actions` +
        // `appeals` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.3-5.5).
        //
        // DECYZJA WŁAŚCICIELA, 2026-09-07 — 36 MIESIĘCY. Liczba jest
        // niezmieniona od pierwszej wersji tego automatu, ale PODSTAWA
        // PRAWNA ZMIENIŁA SIĘ w drugiej turze (po zewnętrznej ocenie prawnej,
        // `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §A/§B.1): pierwsza
        // wersja opierała retencję WPROST na art. 442¹ k.c., a ten przepis
        // ustala PRZEDAWNIENIE roszczenia, nie obowiązek archiwizacji —
        // sam fakt, że ktoś kiedyś może pozwać, jest za ogólną podstawą
        // przetwarzania. Właściwa podstawa to art. 6 ust. 1 lit. f RODO
        // (uzasadniony interes) z pisemnym testem celu, konieczności
        // i równowagi — patrz ADR §5.6. Art. 442¹ k.c. ZOSTAJE w tym teście
        // jako JEDEN Z ELEMENTÓW oceny interesu (pomaga oszacować, jak długo
        // spór o decyzję moderacyjną jest prawdopodobny), nie jako samodzielna
        // podstawa przechowywania. Art. 17 ust. 3 lit. e RODO działa jako
        // wyjątek od usunięcia w KONKRETNYM, udokumentowanym przypadku
        // (`docs/legal/MODERATION_PLAYBOOK.md`), nie jako uniwersalna podstawa
        // całego archiwum.
        //
        // Wspólny okres dla wszystkich trzech tabel, liczony od ZAMKNIĘCIA
        // sprawy: `reports.resolved_at` dla status IN ('resolved','rejected'),
        // `moderation_actions.created_at` (decyzja jest niemutowalna —
        // `ModerationAction::UPDATED_AT === null`), `appeals.decided_at` dla
        // status IN ('upheld','overturned'). Sprawy wciąż otwarte NIGDY nie
        // są kandydatem, niezależnie od wieku.
        //
        // TA SAMA LICZBA DLA WSZYSTKICH TRZECH TABEL, CELOWO. Gdyby `appeals`
        // miało dłuższy okres niż `moderation_actions`, reguła kaskady
        // (`appeals.moderation_action_id` ma `cascadeOnDelete` — patrz ADR §4)
        // skutecznie wymuszałaby, że realny okres `moderation_actions` jest
        // okresem jego odwołania, niezależnie od tego, co tu wpisano. Równa
        // liczba usuwa tę pułapkę wprost, zamiast zostawiać ją do odkrycia
        // przy pierwszym audycie.
        //
        // Egzekwuje `kuking:sprzataj-sprawy-moderacyjne` (jedna komenda dla
        // wszystkich trzech tabel — ADR §5.3: dziennik działania ma opisywać
        // całą "sprawę" spójnie, a nie trzy niezależne komendy uruchamiane
        // w dowolnej kolejności), `App\Domain\Compliance\PrzedawnioneSprawyModeracyjne`.
        'case_retention_months' => (int) env('KUKING_CASE_RETENTION_MONTHS', 36),
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

        // KIEDY TO WYDANIE POWSTAŁO — data i godzina, nie skrót.
        //
        // Skrót commita odpowiada na pytanie „co dokładnie działa", ale nie
        // odpowiada na to, które właściciel zadaje częściej: „czy to, na co
        // patrzę, jest już po mojej ostatniej poprawce". Siedem znaków
        // szesnastkowych tego nie mówi nikomu — trzeba je porównać z historią
        // gita. Data i godzina mówią to od razu.
        //
        // RAILWAY NIE WSTRZYKUJE CZASU WDROŻENIA — sprawdzone, nie ma takiej
        // zmiennej wśród `RAILWAY_*`. Znacznik zapisuje więc BUILD obrazu
        // (Dockerfile, warstwa tuż po skopiowaniu kodu) do pliku niżej,
        // w UTC, w formacie ISO-8601. Warstwa unieważnia się przy każdej
        // zmianie kodu, więc znacznik odpowiada wydaniu, a nie dacie
        // pierwszego builda.
        //
        // Zmienna środowiskowa wygrywa z plikiem — po to, żeby dało się to
        // nadpisać bez przebudowy obrazu (i żeby test miał czym sterować).
        'wydano' => env('KUKING_WYDANO'),

        // `bootstrap/`, nie `storage/`: `storage/` bywa wolumenem podpiętym
        // przy starcie kontenera i wtedy zasłania to, co leży w obrazie.
        'plik_wydania' => base_path('bootstrap/wydanie.txt'),
    ],
];
