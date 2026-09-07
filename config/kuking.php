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

    'analytics' => [
        // Ile dni trzymamy wiersze `product_signals` (issue #115), zanim
        // komenda `kuking:sprzataj-sygnaly` je skasuje. To są zdarzenia
        // techniczne (nieudane wgranie zdjęcia, wykonane wyszukiwanie),
        // przydatne do wykrywania trendu w ostatnich tygodniach — nie mamy
        // powodu trzymać ich bezterminowo, a minimalizacja danych (AGENTS.md
        // §7) jest zasadą domyślną, nie wyjątkiem od niej.
        'signal_retention_days' => (int) env('KUKING_SIGNAL_RETENTION_DAYS', 90),
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
