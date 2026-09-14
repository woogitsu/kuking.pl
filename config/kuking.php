<?php

declare(strict_types=1);
use App\Support\Facebook;

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

    /*
    |--------------------------------------------------------------------------
    | Profil
    |--------------------------------------------------------------------------
    */
    'profil' => [
        /*
         * ILE ZNAKÓW MOŻE MIEĆ NAZWA POKAZYWANA (`display_name`).
         *
         * DLACZEGO TO JEST LICZBA O UKŁADZIE, A NIE O UPRZEJMOŚCI.
         * Nazwa autora w karcie wpisu jest ODNOŚNIKIEM, a odnośnik jest
         * kontrolką, którą przeglądarka po Tab przewija w widok. Kontrolki
         * WYŻSZEJ NIŻ OKNO nie da się pokazać w całości żadnym przewijaniem
         * — ani rezerwą nad paskiem (D-184), ani niczym innym.
         *
         * ZMIERZONE (`scripts/glowka-karty-wpisu.mjs`, okno 320 px, czcionka
         * przeglądarki 200%, wysokość odnośnika nazwy; wiersz ma tam 55,8 px):
         *
         *     10 zn.  →  99,8 px       57 zn.  →  490,38 px
         *     20 zn.  →  211,39 px     69 zn.  →  601,97 px
         *     40 zn.  →  378,78 px     83 zn.  →  713,56 px
         *     47 zn.  →  434,58 px     99 zn.  →  825,16 px
         *
         * Przy dawnym limicie 100 znaków sama nazwa brała 825 px, czyli
         * WIĘCEJ niż okno, w którym mierzy automat dostępności (740 px).
         *
         * LICZBA JEST TU, A NIE W CZTERECH KONTROLERACH, bo w czterech
         * miejscach rozjedzie się przy pierwszej zmianie — a rozjazd znaczy
         * tutaj „przez rejestrację wejdzie nazwa, której ustawienia już nie
         * przyjmą". Kolumna w bazie zostaje przy 100 znakach ŚWIADOMIE: baza
         * ma pomieścić to, co już w niej leży, a bramką jest walidacja.
         * Pilnuje tego `DlugoscNazwyProfiluTest`.
         */
        'dlugosc_nazwy' => 40,
    ],

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

        /*
         * PUBLICZNE ADRESY BUCKETÓW — LISTA DO OSTRZELANIA, NIE DO UŻYWANIA.
         *
         * Ta lista nie służy do budowania adresów zdjęć. Żaden kod serwujący
         * jej nie czyta — adresem zdjęcia jest trasa `media.show` (audyt
         * W7-02). Służy WYŁĄCZNIE bramce `kuking:bramka-r2`, która pod każdy
         * z tych adresów wysyła prawdziwe żądanie po prawdziwy oryginał
         * i wymaga odmowy.
         *
         * PO CO TO ISTNIEJE. Issue #120 wymaga dowodu, że oryginał nie wyjdzie
         * „przez KAŻDĄ publiczną ścieżkę". Bramka umiała wyprowadzić
         * z konfiguracji tylko jedną z nich — endpoint konta S3 — bo
         * pozostałe dwie nie są w tym repozytorium zapisane nigdzie: własna
         * domena (`cdn.kuking.pl`) i `r2.dev` żyją w panelu Cloudflare,
         * a klucz `url` został z dysków mediów świadomie zdjęty. Bramka
         * MILCZAŁA więc o najgroźniejszej drodze — tej, którą naprawdę idzie
         * przeglądarka — i świeciła na zielono, nie zapytawszy o nią ani razu.
         *
         * DLACZEGO WYPISUJE SIĘ TU TEŻ ADRESY, KTÓRE MAJĄ BYĆ WYŁĄCZONE.
         * To jest cała istota tej listy i najłatwiejsza rzecz do zrozumienia
         * na odwrót. Adres, którego nikt nie zadeklarował, nie zostanie
         * zapytany — a niezapytany adres nie jest dowodem na nic. Żeby bramka
         * udowodniła, że `r2.dev` jest wyłączone, MUSI dostać ten adres
         * `pub-….r2.dev` i dostać spod niego odmowę. Puste to nie „nic nie
         * jest publiczne", to „nie wiemy" — i bramka tak to właśnie liczy:
         * jak nieprzejście.
         *
         * Format: adresy rozdzielone przecinkami, z protokołem, bez klucza
         * na końcu — bramka dokleja klucz oryginału z bazy sama. Na przykład
         * `https://cdn.kuking.pl,https://pub-abc123.r2.dev`.
         *
         * Domyślnie pusto, bo wartość jest inna dla każdego środowiska i nie
         * ma sensownej wartości domyślnej. Pusto na dysku lokalnym nic nie
         * psuje — tam bramka odmawia startu wcześniej, na sterowniku dysku.
         */
        'publiczne_adresy' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('KUKING_R2_PUBLICZNE_ADRESY', ''))),
            static fn (string $adres): bool => $adres !== '',
        )),

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
        // Dodanie prawdziwej obsługi (libheif + Imagick w obrazie Dockera) to
        // osobna decyzja z realnym kosztem — ROZSTRZYGNIĘTA jako „nie teraz"
        // w `docs/DECISIONS.md`, D-064 (issue #119). Zanim zmienisz tę listę,
        // przeczytaj D-064 — jest tam rachunek kosztu i próg, przy którym
        // decyzja ma zostać zrewidowana.
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
        //
        // NIE MA TU `podglad` I TO JEST CELOWE. Ta lista mówi, co liczy
        // `ProcessUploadedImage`; podgląd powstaje wcześniej i gdzie indziej
        // (`PodgladOdRazu`, w żądaniu wgrywającym). Dopisanie go tutaj
        // kazałoby zadaniu w tle policzyć drugi raz plik, który już leży
        // w buckecie — koszt bez żadnego zysku.
        'variants' => [
            'thumb' => 320,
            'feed' => 960,
            'large' => 1600,
        ],

        /*
         * PODGLĄD OD RAZU (issue #430).
         *
         * Wgranie zdjęcia i publikacja wpisu to jedno żądanie, więc w chwili
         * renderowania strony wpisu wariantów z kolejki nie ma jeszcze
         * ŻADNYCH — nie z przeciążenia, tylko z kolejności. `PodgladOdRazu`
         * robi jeden mały wariant synchronicznie, żeby autorka zobaczyła
         * swoje zdjęcie, a nie zdanie o nim.
         *
         * `krawedz` = 640 px. Zmierzone dla zdjęcia 4032×3024 (12,2 Mpx):
         * podgląd waży 61,5 kB przy 6,14 MB oryginału (102× mniej) i 173,2 kB
         * wariantu `feed`. 640 px wystarcza na pełną szerokość karty na
         * telefonie (320–414 px) także przy dwukrotnej gęstości pikseli,
         * a po przyjściu prawdziwych wariantów zostaje w `srcset` jako
         * uczciwy kandydat między `thumb` (320) a `feed` (960).
         *
         * `max_megapixels` = 25. TO NIE JEST OSTROŻNOŚĆ NA ZAPAS, tylko
         * granica pamięci kontenera web. Zmierzone szczyty RSS procesu przy
         * robieniu podglądu: 12,2 Mpx → 101 MB, 24,5 Mpx → 154 MB,
         * 49,9 Mpx → 239 MB. libgd alokuje bitmapę POZA licznikiem PHP, więc
         * `memory_limit` tego nie zatrzyma — proces znika zabity przez OOM
         * kontenera, bez wyjątku i bez śladu w dzienniku (patrz
         * `docker/php.ini`). Kontener web ma 1 GB na wszystkie procesy
         * PHP-FPM naraz.
         *
         * 25 Mpx przepuszcza każdy realny telefon: standardowe 12 Mpx
         * i 24 Mpx, które daje iPhone z matrycą 48 Mpx. Powyżej progu
         * podglądu nie ma i zdjęcie pokazuje się dopiero po przetworzeniu
         * w tle, gdzie worker ma własną pamięć (`QUEUE_MEMORY`).
         *
         * Zero albo wartość ujemna wyłącza podgląd w całości — przydaje się,
         * gdyby kiedyś trzeba było odciążyć web jednym ustawieniem, bez
         * wdrożenia. Serwis działa wtedy tak jak przed #430.
         */
        'podglad' => [
            'krawedz' => (int) env('KUKING_MEDIA_PODGLAD_KRAWEDZ', 640),
            'max_megapixels' => (int) env('KUKING_MEDIA_PODGLAD_MAX_MEGAPIXELS', 25),
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
        // WYJĄTEK: `index_page_size` niżej — to zwykły rozmiar strony
        // (ten sam wzorzec co `feed.page_size`, `comments.page_size`), nie
        // limit produktowy pilnowany w wielu miejscach, więc czyta go wprost
        // `TagController::index()`.

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

        // Ile tematów na "stronę" pokazuje spis wszystkich tematów
        // (#273, D-087). Bez infinite scroll — przycisk „Pokaż więcej",
        // jak wszędzie indziej (`<x-show-more>`). Słownik z D-026 ma
        // ~1250 nazw kanonicznych, więc jedna niestronicowana strona
        // renderowałaby naraz ponad tysiąc odnośników.
        'index_page_size' => (int) env('KUKING_TAGS_INDEX_PAGE_SIZE', 100),
    ],

    'text' => [
        /*
         * Skala tekstu ustawiana przez użytkownika w /ustawienia/czytelnosc.
         * Wartości w procentach; muszą mieścić się w CHECK z migracji
         * (70–140 od `2026_09_11_600000_rozszerz_skale_tekstu_w_dol`).
         *
         * KOLEJNOŚĆ JEST KOLEJNOŚCIĄ NA EKRANIE — rosnąco, od najmniejszej.
         * Domyślna (100) wypada w środku i to jest w porządku: lista
         * uporządkowana według rozmiaru jest do przejrzenia jednym spojrzeniem,
         * a lista zaczynająca się od domyślnej i skacząca w dwie strony nie.
         *
         * TRZY MNIEJSZE SĄ NOWE. Zasada „tekst ≥ 18 px" z AGENTS.md dotyczy
         * DOMYŚLNEGO wyglądu — 100% nadal daje 18 px. Niżej schodzi wyłącznie
         * ten, kto sam tak ustawi, i tylko na swoim koncie.
         */
        'scales' => [70, 80, 90, 100, 112, 125, 140],

        /*
         * PODPISY POD PODGLĄDEM — słowa, nie procenty.
         *
         * Człowiek wybierający rozmiar tekstu nie myśli w procentach i nie ma
         * powodu, żeby zaczynał. Podgląd pokazuje zdanie w prawdziwym
         * rozmiarze, a podpis nazywa je słowem.
         *
         * Stoją TUTAJ, obok `scales`, a nie w widoku, bo do 11 września 2026
         * były łańcuchem `@if($scale === 100) … @elseif` w Blade — przy
         * czterech rozmiarach dało się to przeczytać, przy siedmiu już nie,
         * a rozmiar bez podpisu zniknąłby po cichu, zostawiając pusty wiersz.
         * Test `SkalaTekstuDzialaTest` pilnuje, żeby każdy rozmiar miał podpis.
         */
        'scale_labels' => [
            70 => 'Bardzo mały',
            80 => 'Mały',
            90 => 'Trochę mniejszy',
            100 => 'Zwykły',
            112 => 'Trochę większy',
            125 => 'Duży',
            140 => 'Bardzo duży',
        ],

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

    /*
    |--------------------------------------------------------------------------
    | Logowanie linkiem e-mail — „wyślij mi link" (issue #25, D-056)
    |--------------------------------------------------------------------------
    |
    | Droga wejścia dla osób, które gubią hasła — dla naszej grupy DROGA
    | PODSTAWOWA, nie awaryjna (`docs/research/AUDIENCE_50_PLUS.md`: 12,3%
    | osób w wieku 65-74 ma podstawowe umiejętności cyfrowe, a hasło i e-mail
    | są murem). Hasło zostaje jako droga równoległa i nikomu go nie
    | odbieramy — nie zabiera się ludziom tego, co już umieją.
    |
    | LINK JEST HASŁEM JEDNORAZOWYM WYSŁANYM POCZTĄ. Stąd wszystkie liczby
    | niżej: krótki termin, jeden token na konto, twarde limity próśb i —
    | osobno — budżet listów, bo poczty mamy skończoną ilość.
    |
    */

    'login_link' => [
        /*
         * WYŁĄCZNIK CAŁEJ FUNKCJI — jedyna droga wycofania BEZ wdrażania
         * migracji i bez danych do posprzątania (ta sama zasada co przy
         * `formularze.klucz_wyslania_wlaczony` i przy kluczach Turnstile).
         *
         * `false` znaczy: wejście z ekranu logowania znika, a formularz
         * i wszystkie linki będące w drodze odpowiadają ekranem „ta droga
         * jest teraz zamknięta, zaloguj się hasłem". Tabela zostaje
         * nietknięta, konta działają dalej, nikt nie traci dostępu — bo
         * hasło nigdy nie przestało być drogą równoległą.
         */
        'wlaczone' => (bool) env('KUKING_LOGOWANIE_LINKIEM', true),

        /*
         * ILE MINUT ŻYJE LINK.
         *
         * TRZYDZIEŚCI, a nie piętnaście z pierwszego szkicu issue #25 — i to
         * jest świadome odstępstwo, nie przeoczenie.
         *
         * Piętnaście minut to liczba z serwisów, w których człowiek siedzi
         * przy komputerze i czeka na list. Nasza droga wygląda inaczej
         * i została opisana w researchu: prośba idzie z komputera, a poczta
         * jest w telefonie leżącym w drugim pokoju. „Idź po telefon,
         * odblokuj, znajdź list wśród czterdziestu innych, przeczytaj,
         * kliknij" to realnie kilkanaście minut, nie dwie. Link wygasający
         * w połowie tej drogi jest gorszy niż jego brak: człowiek dostaje
         * komunikat o błędzie po tym, jak zrobił wszystko dobrze, i prosi
         * o drugi list — czyli wygaśnięcie SAMO GENERUJE ruch pocztowy,
         * którego budżet niżej ma pilnować.
         *
         * DLACZEGO NIE WIĘCEJ. `docs/legal/SECURITY_BASELINE.md` §3 daje
         * linkowi resetu hasła maksimum 60 minut. Ten link jest MOCNIEJSZY
         * od tamtego (loguje od razu, nie prosi o ustawienie nowego hasła),
         * więc jego okno nie ma prawa być dłuższe — połowa tamtego jest
         * właściwą proporcją. Przez cały ten czas jest to ważne hasło
         * jednorazowe leżące w cudzej skrzynce.
         */
        'waznosc_minut' => (int) env('KUKING_LOGOWANIE_LINKIEM_WAZNOSC', 30),

        /*
         * ILE LISTÓW Z LINKIEM WOLNO WYSŁAĆ W CIĄGU DOBY — CAŁEMU SERWISOWI.
         *
         * TO NIE JEST LIMIT ZAPYTAŃ. Limity chronią pojedyncze konto
         * i pojedynczy adres IP; ten wpis chroni COŚ INNEGO i nie da się go
         * tamtymi zastąpić: WSPÓLNĄ PULĘ POCZTY. EmailLabs na planie
         * darmowym daje 300 listów na dobę na CAŁY serwis — potwierdzenia
         * rejestracji, przypomnienia hasła, powiadomienia i te linki idą
         * z jednego wiadra.
         *
         * Bez tego wpisu 500 kont po dwie prośby dziennie (fala migracyjna
         * z Garnek.pl, o której mówi właściciel) zjada całą dobową pulę,
         * a pierwszą rzeczą, która przestaje działać, jest POTWIERDZENIE
         * REJESTRACJI — czyli nowi ludzie nie wchodzą w ogóle, a przyczyna
         * siedzi kilka warstw dalej.
         *
         * SKĄD 120: dwie piąte puli. Zostawia 180 listów na wszystko inne,
         * a przy rachunku z D-056 starcza dla około 100 osób dziennie
         * wchodzących tą drogą. Po wykupieniu większego planu u dostawcy to
         * jest jedna liczba do podniesienia, bez zmiany kodu.
         *
         * PO WYCZERPANIU BUDŻETU NIE MILCZYMY. Formularz mówi wprost, że
         * dziś już listu nie wyślemy, i odsyła do hasła oraz do człowieka
         * pod adresem kontaktowym. Cicha odmowa byłaby tu najgorszym
         * możliwym zachowaniem — to ten sam kształt awarii co
         * `MAIL_MAILER=log` (`App\Support\Poczta`).
         */
        'dzienny_budzet' => (int) env('KUKING_LOGOWANIE_LINKIEM_BUDZET', 120),

        /*
         * LIMIT PRÓŚB NA JEDEN ADRES E-MAIL.
         *
         * Osobno od `limits.login_link` niżej, bo tamten liczy się po
         * adresie IP i nie widzi kogoś, kto zalewa CUDZĄ skrzynkę z wielu
         * miejsc — a to jest tutaj najtańsze nadużycie: każda prośba to
         * jeden list w skrzynce osoby, która o nic nie prosiła, i jeden
         * list mniej w dobowej puli.
         *
         * Trzy na godzinę: człowiek prosi raz, po pięciu minutach jeszcze
         * raz („może nie doszło"), a trzecia próba jest zapasem. Licznik
         * chodzi po SKRÓCIE adresu (`App\Support\Skrot`), więc w tabeli
         * `cache` nie leży cudzy adres e-mail — ta sama lekcja co
         * w `App\Support\KluczeLimitow`.
         *
         * LICZNIK RUSZA PRZY KAŻDYM WYSŁANIU FORMULARZA, także dla adresu,
         * na który nie ma konta. Inaczej sam fakt „ten formularz jeszcze
         * mnie nie zatrzymał" odpowiadałby na pytanie, czy konto istnieje.
         */
        'limit_na_adres' => ['proby' => 3, 'minuty' => 60],

        /*
        |----------------------------------------------------------------------
        | ZAPROSZENIE DO ZAŁOŻENIA KONTA — adres BEZ konta (issue #25, D-085)
        |----------------------------------------------------------------------
        |
        | Druga połowa tej samej drogi. Do 10 września 2026 adres, na którym
        | nie ma konta, dostawał zielone „wysłaliśmy wiadomość" i NIC WIĘCEJ —
        | bo nie było dokąd wysłać, a odpowiedź musi wyglądać identycznie dla
        | adresu z kontem i bez konta (D-056). Zdarzyło się to 63-letniej
        | osobie, która chciała założyć konto: czekała na wiadomość, która nie
        | miała przyjść.
        |
        | Od teraz taki adres dostaje wiadomość z linkiem prowadzącym na
        | DOKOŃCZENIE ZAKŁADANIA KONTA — z adresem wpisanym i już
        | potwierdzonym (kliknięcie linku z własnej skrzynki JEST dowodem jej
        | posiadania, więc drugiej wiadomości weryfikacyjnej nie wysyłamy).
        |
        | PRYWATNOŚĆ Z D-056 JEST PO TEJ ZMIANIE MOCNIEJSZA, NIE SŁABSZA:
        | w obu przypadkach naprawdę wychodzi wiadomość, więc nie ma już
        | różnicy „wysłano / nie wysłano" do wykrycia.
        |
        */
        'zaproszenia' => [
            /*
             * WYŁĄCZNIK TEJ POŁOWY DROGI — osobny od `login_link.wlaczone`.
             *
             * `false` przywraca zachowanie z D-056 (adres bez konta nie
             * dostaje nic) BEZ wycofywania migracji i bez wyłączania
             * logowania linkiem. Ekran odpowiada dalej identycznie, więc
             * wyłączenie nie zdradza niczego o żadnym adresie — jedyną
             * różnicą jest to, że osoba bez konta znowu nie dostanie
             * wiadomości.
             */
            'wlaczone' => (bool) env('KUKING_ZAPROSZENIA_DO_REJESTRACJI', true),

            /*
             * ILE GODZIN ŻYJE ZAPROSZENIE — DWADZIEŚCIA CZTERY, nie 30 minut
             * jak link do logowania. To jest świadome odstępstwo od D-056
             * i ma dwa powody, oba w tę samą stronę.
             *
             * 1. TO POŚWIADCZENIE JEST SŁABSZE. Link do logowania wpuszcza na
             *    istniejące konto — na cudze zdjęcia, cudze wpisy, cudzy
             *    profil. Ten link nie wpuszcza nigdzie: prowadzi na PUSTY
             *    formularz rejestracji z wpisanym adresem. Kto go przechwyci,
             *    może najwyżej założyć konto na tej skrzynce — a to potrafi
             *    zrobić także bez nas, wchodząc na /register i klikając
             *    w zwykłą wiadomość weryfikacyjną z tej samej skrzynki.
             *    Zaproszenie nie daje mu więc żadnej nowej władzy.
             *
             * 2. DROGA PO KLIKNIĘCIU JEST DŁUŻSZA. Po linku do logowania
             *    zostaje jeden przycisk. Tu zostaje CAŁY formularz: nazwa
             *    użytkownika (o którą właśnie odbiła się osoba, dla której to
             *    piszemy), hasło ≥ 10 znaków sprawdzane w wyciekach, dwa
             *    haczyki. Trzydzieści minut znaczyłoby, że zaproszenie wygasa
             *    w środku wymyślania nazwy — czyli człowiek dostaje komunikat
             *    o błędzie po tym, jak zrobił wszystko dobrze, i wraca na
             *    początek. Dokładnie ten błąd naprawiamy.
             *
             * DLACZEGO NIE WIĘCEJ. Tyle samo, ile żyje zamówiona zmiana
             * adresu (`account.email_change_ttl_hours`), a TAMTO poświadczenie
             * jest mocniejsze od tego (kończy przejęcie istniejącego konta).
             * Skoro tamtemu wystarcza doba, temu nie wolno dać więcej. Doba to
             * jedna noc: kto zajrzy do skrzynki nazajutrz, zdąży.
             */
            'waznosc_godzin' => (int) env('KUKING_ZAPROSZENIA_WAZNOSC_GODZIN', 24),

            /*
             * ILE ZAPROSZEŃ WOLNO WYSŁAĆ W CIĄGU DOBY — CAŁEMU SERWISOWI.
             *
             * SUFIT WEWNĄTRZ SUFITU, nie obok niego. Zaproszenie zajmuje
             * miejsce w TYM SAMYM dobowym budżecie co wiadomość z linkiem do
             * logowania (`dzienny_budzet`, 120) — i dodatkowo w tym, niższym.
             * Dzięki temu podział całego wiadra 300 listów (sekcja `poczta`)
             * NIE ZMIENIA SIĘ ANI O JEDEN LIST: te 40 to część tamtych 120,
             * a nie nowa pozycja w rachunku.
             *
             * PO CO WIĘC DRUGI LICZNIK. Bo ta zmiana otwiera wektor, którego
             * wcześniej nie było: do 10 września adres bez konta NIE GENEROWAŁ
             * ŻADNEJ WYSYŁKI, więc automat wpisujący wymyślone adresy nie
             * potrafił wysłać ani jednej wiadomości. Teraz potrafi. Rachunek
             * najgorszego dnia: limit po IP to 5/60 min, czyli 120 próśb na
             * dobę z jednego łącza — dokładnie tyle, ile ma cały dobowy
             * budżet. Bez tego sufitu JEDEN sprawca z jednego łącza (albo
             * kilku z kilku) zjadałby całe 120 i pierwszą rzeczą, która by
             * przestała działać, jest WEJŚCIE NA KONTO LINKIEM — dla części
             * naszych ludzi droga podstawowa, nie awaryjna (D-056).
             *
             * SKĄD 40. Realne zapotrzebowanie jest o rząd wielkości mniejsze:
             * przy 500 kontach i ~20 prośbach o link dziennie (rachunek
             * z D-056) adresy bez konta to pomyłki i osoby, które jeszcze się
             * nie zarejestrowały — kilka na dobę, w tygodniu fali z Garnek.pl
             * może 10-20. Czterdzieści daje temu 2-4-krotny zapas, a w dniu
             * nadużycia ogranicza szkodę do 40 wiadomości do osób, które
             * o nic nie prosiły, i zostawia 80 listów na prawdziwe logowania.
             *
             * CO WIDZI CZŁOWIEK PO WYCZERPANIU TEGO SUFITU: dokładnie to samo
             * co przed — ekran nie może się zmienić, bo zmiana byłaby
             * wyrocznią „na tym adresie nie ma konta", i to wyrocznią, którą
             * napastnik umie WYWOŁAĆ sam (wysłać 40 zaproszeń, a potem
             * odczytywać różnicę). Cena jest wypisana wprost w D-085: w takim
             * dniu osoba bez konta znowu nie dostanie wiadomości. Dlatego
             * sufit stoi tak wysoko nad zapotrzebowaniem, a ekran
             * `/logowanie/link` mówi na stałe, co zrobić, gdy wiadomość nie
             * przychodzi.
             *
             * `0` znaczy „dziś nie wysyłamy żadnych zaproszeń" i jest
             * poprawną, świadomą konfiguracją awaryjną — linki będące
             * w drodze dalej działają.
             */
            'dzienny_sufit' => (int) env('KUKING_ZAPROSZENIA_BUDZET', 40),
        ],
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

        /*
         * OKNO, W KTÓRYM DRUGI IDENTYCZNY KOMENTARZ JEST TYM SAMYM
         * KOMENTARZEM (audyt podwójnego wysłania, 12 września 2026).
         *
         * DLACZEGO KOMENTARZ NIE MA KLUCZA WYSŁANIA. Formularz komentarza
         * stoi w jednym, wspólnym komponencie (`components/comment-thread`)
         * używanym przez trzy ekrany, a ukrytego pola nie da się do niego
         * dołożyć bez zmiany pliku, który w tej sesji należy do kogoś innego.
         * Zamiast tego `PublishComment` bierze BLOKADĘ W BAZIE na tożsamości
         * wysłania (autor + treść + miejsce + wątek) i POD NIĄ sprawdza, czy
         * taki komentarz już powstał — constraint albo blokada z rewalidacją,
         * nigdy samo `exists()` (D-079).
         *
         * DLACZEGO OKNO, A NIE UNIKALNOŚĆ NA ZAWSZE. „Pyszne!" pod dwoma
         * różnymi zdjęciami tej samej osoby to dwie różne rozmowy, a to samo
         * słowo pod tym samym zdjęciem za miesiąc to nowa reakcja, nie
         * duplikat. Zakaz bez okna wyciszałby rozmowę — czyli robiłby to,
         * czego `NotifyUser` w tym repozytorium wprost odmawia.
         *
         * SKĄD MINUTA. Podwójne kliknięcie na wolnym łączu mieści się
         * w sekundach; „kliknąłem, nic się nie stało, kliknąłem jeszcze raz"
         * — w kilkunastu. Minuta obejmuje jedno i drugie z zapasem, a wpisanie
         * ŚWIADOMIE tego samego zdania pod tym samym wpisem w ciągu minuty
         * nie jest zachowaniem, które ten serwis musi obsłużyć.
         *
         * `0` WYŁĄCZA MECHANIZM: blokada nie jest zakładana, powtórki wracają.
         * To jest wyjście awaryjne tej samej klasy co wyłącznik wyżej.
         */
        'okno_powtorzenia_komentarza_sekund' => (int) env('KUKING_OKNO_POWTORZENIA_KOMENTARZA', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile — warunek wysłania siedmiu formularzy publicznych
    |--------------------------------------------------------------------------
    |
    | D-050 (odwraca poz. 1.11 z `docs/INSPIRATION_DECISIONS.md`). Turnstile
    | stoi na SIEDMIU formularzach publicznych — wszędzie tam, gdzie do
    | serwisu wchodzi ktoś niezalogowany.
    |
    | Z KLUCZAMI TURNSTILE JEST WARUNKIEM WYSŁANIA, NIE FILTREM. Brak tokenu
    | odrzuca — decyzja właściciela z 9 września 2026, zaostrzająca pierwszą
    | wersję D-050, która puste pole przepuszczała. Limity zapytań z `limits`
    | niżej, `klucz_wyslania` i weryfikacja adresu e-mail ZOSTAJĄ: captcha ich
    | nie zastępuje.
    |
    | Razem z tym zaciśnięciem idzie `<noscript>` przy każdym z siedmiu
    | formularzy i osobny komunikat dla kogoś, komu widget się nie dociągnął.
    | Kto zdejmie jedno albo drugie, zostawi ludzi przed martwym przyciskiem.
    | Pełne uzasadnienie: `App\Rules\TurnstileJestPotwierdzony`.
    |
    | BEZ KLUCZY NIC SIĘ NIE ZMIENIA I NIC NIE BLOKUJE — patrz niżej.
    |
    */

    'turnstile' => [
        // Klucz publiczny („Site Key") — wchodzi do HTML-a widgetu, więc nie
        // jest sekretem. Sekret („Secret Key") wychodzi wyłącznie na
        // `siteverify` i nigdy nie trafia do widoku ani do logu.
        //
        // PUSTE = TURNSTILE NIE ISTNIEJE: widget się nie renderuje, reguła
        // walidacji nie odpytuje nikogo i nikogo nie odrzuca. To jest stan
        // domyślny lokalnie, w CI i w testach — po to, żeby wgranie kluczy
        // było jedyną rzeczą, którą właściciel musi zrobić, i żeby brak
        // kluczy nie wywracał ani jednego formularza.
        //
        // ALE cisza na produkcji jest zakazana: gdy `APP_ENV=production`,
        // którekolwiek miejsce niżej jest włączone, a kluczy nie ma, `/health`
        // oddaje `status: degraded` z powodem `turnstile_bez_kluczy` i zapisuje
        // błąd w dzienniku (`App\Http\Controllers\HealthController`). Bez tego
        // mielibyśmy narzędzie, które melduje sukces, nie robiąc nic.
        'klucz_publiczny' => (string) env('TURNSTILE_SITE_KEY', ''),
        'sekret' => (string) env('TURNSTILE_SECRET_KEY', ''),

        // Ile sekund czekamy na odpowiedź `siteverify`. Krótko celowo: to jest
        // cudza usługa stojąca w środku wysyłania NASZEGO formularza. Gdy nie
        // odpowiada, przepuszczamy wysłanie i zapisujemy ostrzeżenie —
        // niedostępność Cloudflare nie może zamykać rejestracji.
        'limit_czasu' => (int) env('TURNSTILE_LIMIT_CZASU', 4),

        /*
         * GDZIE TURNSTILE DZIAŁA. `true` = widget na ekranie, token WYMAGANY
         * (brak tokenu odrzuca wysłanie — D-050, zaostrzenie z 9 września
         * 2026) i weryfikacja tego, który przyszedł. `false` = tego formularza
         * Turnstile nie dotyczy w ogóle (widget się nie renderuje, reguła nie
         * odpytuje Cloudflare i nie wymaga tokenu — także wtedy, gdy ktoś
         * podstawi token ręcznie).
         *
         * `false` jest więc JEDYNĄ drogą wycofania zaciśnięcia dla jednego
         * formularza. Osobnego przełącznika „captcha, ale bez wymagania
         * tokenu" nie ma świadomie: Turnstile przepuszczający puste pole nie
         * chroni przed niczym, bo automat po prostu tego pola nie wysyła.
         *
         * Wszystkie siedem jest włączonych: każdy z tych formularzy jest
         * publiczny i każdy kosztuje nas coś realnego przy nadużyciu — konto
         * do moderowania, list wysłany na cudzy adres, wiadomość w kolejce
         * jedynej osoby, która ją czyta, sprawę z terminem odpowiedzi z DSA,
         * zgadywanie haseł do cudzych kont.
         *
         * WYŁĄCZENIE WSZĘDZIE NARAZ to wyczyszczenie kluczy wyżej — jedno
         * miejsce, bez wdrożenia. Wyłączenie punktowe: `false` niżej.
         */
        'miejsca' => [
            'rejestracja' => (bool) env('TURNSTILE_NA_REJESTRACJI', true),
            'odzyskanie_hasla' => (bool) env('TURNSTILE_NA_ODZYSKANIU_HASLA', true),
            'kontakt' => (bool) env('TURNSTILE_NA_KONTAKCIE', true),
            'zgloszenie_nielegalnej_tresci' => (bool) env('TURNSTILE_NA_ZGLOSZENIU', true),

            /*
             * LOGOWANIE I COFNIĘCIE USUNIĘCIA KONTA — WŁĄCZONE, ZAWSZE.
             *
             * DECYZJA WŁAŚCICIELA z 9 września 2026 (issue #217): „captcha
             * trzeba normalnie zrobić, ten od cloudflare jest nieinwazyjny".
             * Turnstile w trybie Managed przechodzi w przeważającej większości
             * przypadków BEZ ŻADNEJ INTERAKCJI — nie ma obrazków do klikania,
             * więc nie ma bariery, przed którą bronił pierwotny szkic #217
             * („dopiero po nieudanych próbach"). Ten wariant nie powstał
             * i nie jest już potrzebny.
             *
             * Trzy koszyki `login_limits` ZOSTAJĄ bez zmian. Turnstile ich nie
             * zastępuje: limity widzą atak rozproszony po adresach, captcha
             * widzi automat. To dwie różne obrony i chcemy obu naraz.
             *
             * `SECURITY_BASELINE.md` §4 opisuje ten sam stan — nie rozjeżdżaj
             * tych dwóch miejsc.
             */
            'logowanie' => (bool) env('TURNSTILE_NA_LOGOWANIU', true),
            'cofniecie_usuniecia' => (bool) env('TURNSTILE_NA_COFNIECIU_USUNIECIA', true),

            /*
             * SIÓDME MIEJSCE, DOŁOŻONE 10 WRZEŚNIA 2026 (issue #25, D-056):
             * „Wyślij mi link do zalogowania".
             *
             * Należy do tej samej rodziny co `odzyskanie_hasla` i z tego
             * samego powodu: formularz jest publiczny, wysyła list na CUDZY
             * adres i zużywa dobową pulę poczty dzieloną z potwierdzeniami
             * rejestracji. Automat bez tej bramki zalewa skrzynkę wybranej
             * osoby i wyczerpuje pulę listów całego serwisu — a to drugie
             * boli wszystkich, nie tylko ofiarę.
             */
            'logowanie_linkiem' => (bool) env('TURNSTILE_NA_LOGOWANIU_LINKIEM', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wejście kontem Google (issue #258, D-069)
    |--------------------------------------------------------------------------
    |
    | DROGA DODATKOWA, NIGDY JEDYNA. Hasło i link e-mail zostają na ekranie
    | logowania niezależnie od tego, co jest tutaj ustawione.
    |
    | PUSTE KLUCZE = TEJ FUNKCJI NIE MA. Przycisku nie ma na ekranie, trasy
    | odsyłają na logowanie ze zdaniem po polsku, nic się nie psuje. To jest
    | stan domyślny lokalnie, w CI i w testach — po to, żeby wgranie dwóch
    | zmiennych w Railway było jedyną rzeczą, którą właściciel musi zrobić.
    | Dokładnie ta sama zasada co przy kluczach Turnstile wyżej.
    |
    | ALE cisza na produkcji ma być zakazana: gdy `APP_ENV=production`, funkcja
    | jest włączona niżej, a kluczy nie ma, `/health` ma oddawać `degraded`
    | z powodem `google_bez_kluczy` — inaczej mielibyśmy drogę wejścia, która
    | melduje sukces, nie istniejąc.
    |
    | TEGO SYGNAŁU JESZCZE NIE MA — świadomie odłożony, nie przeoczony.
    | `HealthController` przerabia równolegle inne zlecenie (#253/#255),
    | a dwóch agentów w jednym pliku kosztuje więcej niż jeden dzień bez tego
    | sygnału. Gotowe zdanie dla właściciela czeka
    | w `App\Support\Google::komunikatBrakuKluczy()`.
    */
    'google' => [
        /*
         * WYŁĄCZNIK CAŁEJ FUNKCJI — jedyna droga wycofania BEZ migracji.
         *
         * `false` znaczy: przycisk znika z ekranu logowania i rejestracji,
         * a trasy odpowiadają „ta droga jest teraz zamknięta, zaloguj się
         * hasłem". Powiązania w bazie zostają nietknięte, konta działają
         * dalej i NIKT nie traci dostępu — bo konto założone tą drogą ma
         * potwierdzony adres, czyli zostaje mu link e-mail i „Nie pamiętam
         * hasła". Ta sama konstrukcja co `login_link.wlaczone`.
         *
         * Wycofanie MIGRACJI to co innego i ona odmawia, dopóki nie
         * powiesz jej wprost, że wolno skasować powiązania — patrz
         * `2026_09_10_500000_create_tozsamosci_zewnetrzne_table`.
         */
        'wlaczone' => (bool) env('KUKING_WEJSCIE_GOOGLE', true),

        // Identyfikator klienta jest PUBLICZNY (wchodzi do adresu, na który
        // odsyłamy człowieka). Sekret wychodzi wyłącznie w żądaniu
        // serwer-serwer o token i nigdy nie trafia do widoku ani do logu.
        'identyfikator_klienta' => (string) env('GOOGLE_CLIENT_ID', ''),
        'sekret_klienta' => (string) env('GOOGLE_CLIENT_SECRET', ''),

        /*
         * Ile sekund czekamy na odpowiedź Google przy wymianie kodu.
         *
         * Dłużej niż przy Turnstile (4 s), bo tu nie ma wariantu
         * „przepuszczamy bez sprawdzenia": jeśli Google nie odpowie, ta
         * osoba po prostu nie wejdzie tą drogą. Ale nie za długo — człowiek
         * patrzy w tym czasie na pustą stronę wracającą z Google.
         */
        'limit_czasu' => (int) env('GOOGLE_LIMIT_CZASU', 6),

        /*
         * Ile minut wolno stać na ekranie domknięcia konta (imię, nazwa,
         * dwa oświadczenia), zanim rozpoznana tożsamość z Google przestanie
         * się liczyć i trzeba będzie kliknąć „Wejdź kontem Google" jeszcze
         * raz.
         *
         * TRZYDZIEŚCI — ta sama liczba i ten sam wywód co przy linku
         * do logowania (D-056): to jest ekran, na którym osoba 65-letnia
         * wymyśla nazwę do adresu profilu i czyta regulamin, więc pięć minut
         * byłoby wyrzuceniem jej z rejestracji za to, że czytała uważnie.
         * Górna granica bierze się z tego, że przez cały ten czas w sesji
         * leży potwierdzona tożsamość, na którą da się założyć konto.
         */
        'waznosc_domkniecia_minut' => (int) env('GOOGLE_WAZNOSC_DOMKNIECIA', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wejście kontem Facebooka (issue #259, D-069, D-098)
    |--------------------------------------------------------------------------
    |
    | DROGA DODATKOWA, NIGDY JEDYNA — dokładnie jak Google wyżej. Hasło i link
    | e-mail zostają na ekranie logowania niezależnie od tego, co jest tutaj.
    |
    | PUSTE KLUCZE = TEJ FUNKCJI NIE MA. Przycisku nie ma na ekranie, trasy
    | odsyłają na logowanie ze zdaniem po polsku, nic się nie psuje. To jest
    | stan domyślny lokalnie, w CI, w testach ORAZ we wszystkich środowiskach
    | preview — i w tym ostatnim przypadku nie jest to niedogodność, tylko
    | jedyne możliwe zachowanie: adresy `*.up.railway.app` są losowe, a Meta
    | dopasowuje adres powrotu znak w znak i nie przyjmuje `*`
    | (docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md §4.4).
    |
    | CZYM TA DROGA RÓŻNI SIĘ OD GOOGLE — jednym zdaniem, bo to zdanie
    | decyduje o bezpieczeństwie: Facebook NIE mówi, czy adres e-mail jest
    | potwierdzony, więc adres z Facebooka nigdy nie łączy z istniejącym
    | kontem i nigdy nie trafia do bazy jako potwierdzony (D-098).
    |
    | Sygnału `/health` o braku kluczy tu jeszcze nie ma — tak samo jak przy
    | Google i z tego samego powodu (`HealthController` jest w rękach innego
    | zlecenia). Gotowe zdanie dla właściciela czeka
    | w `App\Support\Facebook::komunikatBrakuKluczy()`.
    */
    'facebook' => [
        /*
         * WYŁĄCZNIK CAŁEJ FUNKCJI — jedyna droga wycofania BEZ migracji.
         *
         * `false` znaczy: przycisk znika z ekranu logowania i rejestracji,
         * a trasy odpowiadają „ta droga jest teraz zamknięta, zaloguj się
         * hasłem". Powiązania w bazie zostają nietknięte.
         *
         * UWAGA, TU JEST RÓŻNICA WZGLĘDEM GOOGLE, O KTÓREJ TRZEBA WIEDZIEĆ
         * PRZED WYŁĄCZENIEM: konto założone kontem Google ma adres
         * POTWIERDZONY, więc po wyłączeniu tamtej drogi zostaje mu link
         * e-mail. Konto założone kontem Facebooka ma adres niepotwierdzony,
         * dopóki człowiek nie kliknie w naszą wiadomość — więc dla części
         * tych kont wyłączenie tej drogi zostawia tylko „Nie pamiętam
         * hasła" (które i tak wysyła list na ten adres, więc droga istnieje,
         * ale jest dłuższa). Wyłączaj świadomie i uprzedź te osoby.
         */
        'wlaczone' => (bool) env('KUKING_WEJSCIE_FACEBOOK', true),

        // W panelu Meta nazywają się **App ID** i **App Secret**
        // (Settings → Basic). Nazwy zmiennych są symetryczne do Google
        // i takie stoją w runbooku §11. Identyfikator jest publiczny
        // (wchodzi do adresu, na który odsyłamy człowieka); sekret wychodzi
        // wyłącznie w żądaniu serwer-serwer i w HMAC-u `appsecret_proof`.
        'identyfikator_klienta' => (string) env('FACEBOOK_CLIENT_ID', ''),
        'sekret_klienta' => (string) env('FACEBOOK_CLIENT_SECRET', ''),

        /*
         * WERSJA GRAPH API — JEDNA STAŁA, BO MA TERMIN WAŻNOŚCI.
         *
         * To jest ten obowiązek, którego droga Google nie miała wcale
         * (runbook §7.3). Wersje Meta żyją „at least 2 years from release",
         * a po wygaśnięciu wywołania NIE PADAJĄ — spadają cicho na starszą
         * wersję. Dlatego numer stoi w jednym miejscu i da się go podnieść
         * zmienną środowiskową, bez wdrożenia kodu; a do przeglądu
         * kwartalnego (docs/infra/DEPLOYMENT_RUNBOOK.md) doszła pozycja
         * „sprawdź, czy nasza wersja Graph API jeszcze żyje".
         *
         * Pusta wartość wraca do stałej w `App\Support\Facebook`, bo adres
         * bez numeru wersji idzie u Meta na wersję NAJSTARSZĄ Z ŻYWYCH —
         * czyli tę, która wygaśnie najszybciej.
         */
        'wersja_grafu' => (string) env('FACEBOOK_GRAPH_WERSJA', Facebook::WERSJA_GRAFU_DOMYSLNA),

        /*
         * Ile sekund czekamy na odpowiedź Facebooka. Ten sam wywód i ta sama
         * liczba co przy Google — z tą różnicą, że tutaj wywołań jest DWA
         * (token, potem tożsamość), więc w najgorszym razie człowiek czeka
         * dwa razy tyle. Dlatego nie więcej.
         */
        'limit_czasu' => (int) env('FACEBOOK_LIMIT_CZASU', 6),

        /*
         * Ile minut wolno stać na ekranie domknięcia konta albo na ekranie
         * „połącz konto z Facebookiem". Trzydzieści — ta sama liczba i ten
         * sam wywód co przy Google i przy linku do logowania (D-056).
         */
        'waznosc_domkniecia_minut' => (int) env('FACEBOOK_WAZNOSC_DOMKNIECIA', 30),
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

        /*
         * „Wyślij mi link do zalogowania" (issue #25) — licznik PO ADRESIE IP.
         *
         * Niżej niż `password_reset` (5/10 min) i to jest celowe: tamten
         * formularz kończy się listem, po którym trzeba jeszcze wymyślić
         * i wpisać hasło, a ten wysyła gotowe wejście na konto. Pięć na
         * godzinę mieści rodzinę za jednym łączem i osobę, która pomyliła
         * się w adresie, a nie starcza na zalewanie skrzynek.
         *
         * DRUGI, WAŻNIEJSZY LICZNIK JEST PO ADRESIE E-MAIL
         * (`login_link.limit_na_adres`). Ten tutaj nie widzi kogoś, kto
         * zalewa jedną cudzą skrzynkę z wielu miejsc.
         */
        'login_link' => '5,60',

        /*
         * Kliknięcie „Zaloguj mnie" na ekranie z linku (issue #25).
         *
         * OSOBNY KOSZYK OD `login_link` WYŻEJ, choć to ta sama funkcja:
         * nieudane wejście nie ma prawa zjadać budżetu próśb o list. Token
         * ma 64 losowe znaki, więc zgadywania i tak nie ma czego blokować —
         * ten limit chroni bazę przed zapętloną wtyczką i przed kimś, kto
         * postanowił pukać w tę trasę seriami.
         *
         * Dziesięć na dziesięć minut: człowiek klika ten przycisk raz, a przy
         * słabym łączu drugi i trzeci. Liczy się po adresie IP, więc musi
         * pomieścić kilka osób za jednym ruterem.
         */
        'login_link_wejscie' => '10,10',

        /*
         * WEJŚCIE KONTEM GOOGLE (issue #258, D-069) — dwa koszyki.
         *
         * `google_wejscie` liczy KLIKNIĘCIA „Wejdź kontem Google" i powroty
         * z Google. Nie chroni przed zgadywaniem (nie ma czego zgadywać —
         * `state` ma 64 losowe znaki), tylko przed kimś, kto postanowił pukać
         * w tę trasę seriami, i przed zapętloną wtyczką. Dwadzieścia na
         * dziesięć minut, bo licząc po adresie IP musi pomieścić kilka osób
         * za jednym ruterem i człowieka, który raz się rozmyślił i wrócił.
         *
         * `google_domkniecie` liczy WYSŁANIA formularza domknięcia konta —
         * to jest ta trasa, która naprawdę zakłada konto. Tyle samo co
         * `register` (5 na 10 minut) i z tego samego powodu, choć ta droga
         * ma przed sobą mocniejszą bramkę niż captcha: żeby tu dojść, trzeba
         * mieć konto Google z potwierdzonym adresem (patrz D-069, sekcja
         * o Turnstile).
         */
        'google_wejscie' => '20,10',
        'google_domkniecie' => '5,10',

        /*
         * WEJŚCIE KONTEM FACEBOOKA (issue #259) — dwa koszyki, ta sama
         * konstrukcja i te same liczby co przy Google.
         *
         * OSOBNE od `google_*` świadomie: kliknięcia w jedną drogę nie mają
         * prawa zjadać budżetu drugiej. Gdyby oba dostawcy dzielili koszyk,
         * człowiek, który spróbował Google i się rozmyślił, zbliżałby się do
         * limitu na Facebooku — a wyczerpany limit wygląda na ekranie jak
         * awaria serwisu. Pilnuje tego
         * `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`.
         *
         * `facebook_domkniecie` liczy też POST-y „połącz konto", bo to jest
         * druga trasa, która naprawdę dokłada drogę wejścia na konto.
         */
        'facebook_wejscie' => '20,10',
        'facebook_domkniecie' => '5,10',

        /*
         * Ekran zaproszenia do założenia konta — POST-y z niego (D-085).
         *
         * OSOBNY KOSZYK od `login_link_wejscie`, choć liczba jest ta sama
         * i choć to ta sama rodzina dróg: człowiek, który nieudanie klikał
         * „Zaloguj mnie", nie ma tracić prób na „Załóż konto" i odwrotnie.
         * Ta reguła („jeden prefiks to zawsze jeden limit i jedna trasa")
         * jest pilnowana testem
         * `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`.
         *
         * Dziesięć na dziesięć minut, po adresie IP: człowiek klika ten
         * przycisk raz, przy słabym łączu drugi i trzeci, a limit musi
         * pomieścić kilka osób za jednym ruterem. Zgadywania tokenu ten limit
         * nie blokuje i nie musi — 64 losowe znaki nie są do zgadnięcia; on
         * chroni bazę przed zapętloną wtyczką i przed pukaniem seriami.
         */
        'zaproszenie' => '10,10',
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

        /*
         * ODPOWIEDŹ MODERATORA NA WIADOMOŚĆ Z „Napisz do nas" (D-058) —
         * trasa `admin.contact.reply`, jedyna w panelu, która WYSYŁA LIST
         * NA ZEWNĄTRZ.
         *
         * OSOBNY KLUCZ, A NIE WSPÓLNY `moderacja` (120/10), i to jest cała
         * treść tego wpisu. Tamten limit jest świadomie najwyższy w serwisie,
         * bo chroni kolejkę moderacji przed przejętą sesją, a NIE MOŻE
         * zatrzymać jedynego moderatora w środku fali spamu — jego skutkiem
         * jest wiersz w bazie. Tutaj skutkiem jest list wysłany do człowieka
         * z adresu `kontakt@kuking.pl` i zjedzony budżet poczty: EmailLabs
         * na planie darmowym daje 300 listów DZIENNIE, dzielonych
         * z potwierdzeniami rejestracji, przypomnieniami hasła i alarmami
         * moderacyjnymi. Sesja moderatora użyta maszynowo pod limitem
         * `moderacja` wypaliłaby połowę tego budżetu w dziesięć minut
         * i zabrała ludziom możliwość odzyskania hasła.
         *
         * SKĄD DWADZIEŚCIA NA DZIESIĘĆ MINUT. Odpowiedź na wiadomość pisze
         * człowiek własnymi słowami — realnie dwie, trzy na dziesięć minut,
         * i to przy bardzo dobrym poranku. Dwadzieścia zostawia zapas na
         * nadrabianie zaległości i na powtórzenie wysyłki, która się nie
         * udała (issue #234), a jednocześnie ogranicza szkodę z przejętej
         * sesji do dwudziestu listów na okno zamiast stu dwudziestu.
         *
         * SUFITU DZIENNEGO ŚWIADOMIE NIE MA — sprawdzone, nie założone.
         *
         * Stan faktyczny na 10 września 2026: WSPÓLNEGO licznika całej poczty
         * w repozytorium nie ma i `App\Domain\Security\DziennyBudzetListow`
         * mówi to o sobie wprost. Istnieje jeden sufit WŁASNY jednej funkcji —
         * logowania linkiem (D-056, `login_link.dzienny_budzet` = 120) — a
         * reszta puli jest pilnowana PROJEKTOWO: listy natychmiastowe tylko
         * dla kategorii pilnych, resztę zbiera jedno podsumowanie na dobę
         * (`PilnyAlarmModeracyjny`, `kuking:podsumowanie-automatu`).
         *
         * DLACZEGO ODPOWIEDZI NIE POTRZEBUJĄ TEGO, CO POTRZEBOWAŁO LOGOWANIE
         * LINKIEM. Tamten sufit powstał, bo prośbę o list wywołuje KTOKOLWIEK
         * Z ZEWNĄTRZ i pięciuset ludzi zachowujących się zupełnie normalnie
         * zjada dobową pulę bez przekroczenia jakiegokolwiek limitu. Tutaj
         * list wywołuje jedna osoba, po zalogowaniu, z 2FA, pisząc treść
         * własnymi słowami — fan-outu nie ma z czego zrobić. Odpowiedzi to
         * garść listów dziennie, czyli poniżej 2% puli.
         *
         * A sufit postawiony wbrew temu rachunkowi zrobiłby rzecz szkodliwą:
         * odmówiłby wysłania odpowiedzi człowiekowi, który już czeka, w imieniu
         * budżetu, którego nikt nie mierzy. Gdyby kiedyś powstał prawdziwy,
         * WSPÓLNY licznik poczty (np. razem z tygodniowym digestem), TO ON ma
         * być jednym miejscem tej decyzji — nie osobny sufit dopisany tutaj.
         */
        'kontakt_odpowiedz' => '20,10',

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
         * ZDJĘCIE PROFILOWE (`POST /ustawienia/zdjecie`) — OSOBNO OD `ustawienia`.
         *
         * KLUCZ NAZYWA SIĘ `ustawienia_profil` ZE WZGLĘDÓW HISTORYCZNYCH:
         * do wydzielenia osobnego ekranu (D-054) pole pliku stało w formularzu
         * `/ustawienia/profil` i limit pilnował tamtej trasy. Pole się
         * przeniosło, więc limit przeniósł się razem z nim — a zapis profilu
         * bez zdjęcia to znowu zwykły UPDATE jednego wiersza i wrócił do
         * wspólnej grupy `ustawienia`. Usunięcie zdjęcia też tam zostaje.
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

    /*
    |--------------------------------------------------------------------------
    | Kopia bazy poza Railwayem — CZUJKA, nie sama kopia
    |--------------------------------------------------------------------------
    |
    | Kopię robi OSOBNY serwis Railway w obrazie bez PHP (`docker/kopia/`,
    | issue #193, decyzja D-043) — nie ta aplikacja. Aplikacja robi drugą
    | rzecz, której tamten serwis zrobić NIE MOŻE: pilnuje, czy on w ogóle
    | jeszcze chodzi.
    |
    | DLACZEGO TO JEST OSOBNE ZADANIE, A NIE DUBLOWANIE
    | Serwis kopii alarmuje, gdy przebieg mu się nie udał. Nie zaalarmuje,
    | gdy przebiegu NIE BYŁO: skasowany serwis, wyłączony harmonogram,
    | wyczerpany limit, zmieniona nazwa bucketu. Kod, który wtedy nie chodzi,
    | nie może o sobie donieść — i to jest dokładnie ten stan, który #193
    | nazywa najgorszym z możliwych („myślisz, że masz kopię").
    |
    | Ta czujka patrzy z drugiej strony: raz na dobę listuje bucket i dzwoni,
    | gdy najnowsza kopia jest starsza niż `maks_wiek_godzin`.
    |
    | UPRAWNIENIA: aplikacja dostaje token R2 TYLKO DO CZYTANIA tego bucketu
    | (`r2_kopie` w config/filesystems.php). Nie może nic tam zapisać ani
    | skasować, a to, co przeczyta, jest zaszyfrowane kluczem publicznym,
    | którego pary nie ma w żadnym środowisku uruchomieniowym.
    |
    | BRAK KONFIGURACJI = ZERO EFEKTU. Dopóki bucket nie istnieje (stan na
    | 9 września 2026), `AWS_KOPIE_BUCKET` jest puste i czujka milczy —
    | tak samo jak kanał `blad_webhook` przy pustym `LOG_BLAD_WEBHOOK_URL`.
    | Cisza z powodu braku konfiguracji nie może udawać ciszy z powodu
    | „wszystko w porządku", dlatego komenda mówi wprost, że jest wyłączona.
    */
    'kopie' => [
        'dysk' => env('KUKING_KOPIE_DISK', 'r2_kopie'),

        // Ten sam prefiks, co `KOPIA_PREFIKS` w serwisie kopii. Rozjazd tych
        // dwóch wartości daje czujkę, która zawsze widzi pusty katalog
        // i zawsze krzyczy — czyli alarm, który uczy się ignorować.
        'prefiks' => env('KUKING_KOPIE_PREFIKS', 'baza/'),

        // 36 h przy harmonogramie dobowym: jeden przebieg ma prawo wypaść
        // (restart, chwilowa niedostępność R2), dwa już nie.
        'maks_wiek_godzin' => (int) env('KUKING_KOPIE_MAKS_WIEK_GODZIN', 36),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tygodniowe podsumowanie (digest) — issue #11, D-057
    |--------------------------------------------------------------------------
    |
    | Jedyny list, który NIE jest transakcyjny: nikt go nie zamówił kliknięciem
    | w serwisie tuż przed wysyłką. Dlatego wszystko tutaj daje się wyłączyć,
    | a każda liczba jest jawna — poczta ma dziś TWARDY limit 300 listów na
    | dobę na całe konto EmailLabs (plan STARTUP, `docs/decyzje/POCZTA.md` §1)
    | i dzieli go z potwierdzeniami adresu, resetami hasła, ostrzeżeniami
    | o zmianie adresu i powiadomieniami moderacyjnymi.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Poczta: podział jednego wiadra 300 listów na dobę (D-047, D-057)
    |--------------------------------------------------------------------------
    |
    | EmailLabs na planie STARTUP daje **300 listów na dobę na CAŁY serwis**
    | (`docs/decyzje/POCZTA.md` §1). Nie ma tu osobnych pul dla poczty
    | transakcyjnej i dla biuletynu — jest jedno wiadro i wszystko z niego
    | czerpie. Kto pierwszy zużyje, ten pierwszy dostanie; resztę dostawca
    | odrzuca, a odrzucony list PRZEPADA (worker ma `--tries=3
    | --backoff=10,60,300`, więc trzy próby mieszczą się w sześciu minutach
    | tej samej doby i czwartej nie ma).
    |
    | Dlatego funkcje, które wysyłają WIELE listów naraz, mają własne dobowe
    | sufity (`DziennyBudzetListow`), a te sufity muszą się zmieścić pod 300
    | RAZEM Z REZERWĄ na pocztę, bez której nie da się wejść do serwisu.
    | Pilnuje tego `PodzialLimituPocztyTest` — bo suma trzech liczb z trzech
    | różnych sekcji konfiguracji jest dokładnie tym rodzajem rzeczy, którą
    | ktoś kiedyś podniesie w jednym miejscu i nie sprawdzi w pozostałych.
    |
    | PODZIAŁ (dziś, 10 września 2026):
    |
    |   120  logowanie linkiem e-mail  (`login_link.dzienny_budzet`, issue #25)
    |    60  tygodniowe podsumowanie   (`digest.dzienny_limit`, issue #11)
    |   100  rezerwa transakcyjna      (`poczta.rezerwa_transakcyjna`)
    |    20  zapas
    |   ---
    |   300
    |
    | Rezerwa transakcyjna nie jest licznikiem — nic jej nie zajmuje i nic
    | nie sprawdza, czy została. To LICZBA W RACHUNKU: tyle listów zostawiamy
    | wolnych na potwierdzenia rejestracji, przypomnienia hasła, ostrzeżenia
    | o zmianie adresu i powiadomienia moderacyjne. One nie mają sufitu
    | i mieć go nie mogą — reset hasła, który nie doszedł, kończy komuś
    | przygodę z serwisem, a podsumowanie, które nie doszło, jest niczym.
    | Sufity mają wyłącznie funkcje, które wolno przyhamować.
    |
    | CO SIEDZI W TEJ REZERWIE Z KOLEJKI MODERACJI (D-060, dopisane
    | 10 września): dobowe podsumowanie kolejki automatu
    | (`kuking:podsumowanie-automatu`, D-055) i przypomnienie o terminie
    | odwołania (`kuking:pilnuj-terminow-odwolan`,
    | `moderation.appeal_reminder_working_days`). Każde z nich wysyła
    | NAJWYŻEJ JEDEN list na dobę, na jeden adres, i tylko w dniach, w których
    | naprawdę jest o czym pisać — razem najwyżej 2 z tych 100. Dlatego nie
    | mają własnych sufitów: sufit jest narzędziem na funkcje, które wysyłają
    | wiele listów naraz, a nie na te, które wysyłają jeden. Poczty na KAŻDE
    | odwołanie świadomie nie ma (D-060).
    |
    */
    'poczta' => [
        // Limit dostawcy, na dobę, na całe konto. NIE zmieniaj tej liczby
        // „żeby test przeszedł" — zmienia się ona wtedy, gdy właściciel
        // zmieni plan u dostawcy, i wtedy razem z `docs/decyzje/POCZTA.md`.
        'limit_dostawcy_dobowy' => (int) env('KUKING_POCZTA_LIMIT_DOBOWY', 300),

        // Ile listów zostawiamy wolnych na pocztę bez sufitu (patrz wyżej).
        'rezerwa_transakcyjna' => (int) env('KUKING_POCZTA_REZERWA', 100),

        /*
         * ILE DNI TRZYMAMY ODHACZONE ŚLADY NIEUDANYCH LISTÓW
         * (`mail_failures`, issue #234, D-062).
         *
         * Dotyczy WYŁĄCZNIE wierszy odhaczonych, czyli takich, o których
         * właściciel już wie. Nieodhaczonych nie kasuje nic i nigdy — to
         * jedyne miejsce, w którym istnieje wiedza o tym, że komuś nie doszedł
         * list, a wiek jej nie unieważnia.
         *
         * W wierszu nie ma adresu ani treści listu (patrz migracja), więc to
         * nie jest termin z RODO, tylko higiena: tabela ma nie rosnąć bez
         * końca. Zero albo mniej wyłącza sprzątanie.
         */
        'retencja_dni' => (int) env('KUKING_POCZTA_RETENCJA_DNI', 90),

        /*
         * PRÓG OSTRZEŻENIA O KOŃCZĄCYM SIĘ DOBOWYM SUFICIE, W PROCENTACH
         * zużycia (issue #234).
         *
         * Przy 80 ostrzeżenie idzie do dziennika po zużyciu 80% sufitu danej
         * funkcji — czyli ZANIM listy zaczną odbijać się od limitu dostawcy.
         * O to prosi issue #234 wprost: przejście na płatny plan ma dać się
         * zrobić dzień wcześniej, nie w dniu awarii.
         *
         * Ostrzeżenie leci RAZ NA DOBĘ NA FUNKCJĘ (`DziennyBudzetListow`).
         * Bez tego przy sufitcie 120 listów jeden dzień wysyłki dałby
         * dwadzieścia cztery identyczne wpisy, a webhook błędów zamieniłby
         * się w szum, który uczy się ignorować.
         *
         * 100 albo więcej wyłącza ostrzeganie (sufit i tak zatrzyma wysyłkę).
         */
        'prog_ostrzezenia_procent' => (int) env('KUKING_POCZTA_PROG_OSTRZEZENIA', 80),

        /*
         * ILE GODZIN EKRAN MÓWI CZŁOWIEKOWI, ŻE JEGO LIST NIE WYSZEDŁ
         * (D-062 §4).
         *
         * Ekran „Potwierdź adres e-mail" pokazuje zdanie o nieudanej wysyłce
         * tylko wtedy, gdy porażka jest świeża. Po dobie zdanie znika, bo
         * przestaje być pomocne: człowiek ma na tym samym ekranie przycisk
         * „Wyślij wiadomość jeszcze raz", a ostrzeżenie sprzed tygodnia
         * mówiłoby tylko „coś kiedyś nie wyszło".
         */
        'okno_prawdy_godzin' => (int) env('KUKING_POCZTA_OKNO_PRAWDY_GODZIN', 24),
    ],

    'digest' => [
        /*
         * WYŁĄCZNIK CAŁOŚCI — jedna zmienna środowiskowa (D-057).
         *
         * DOMYŚLNIE WYŁĄCZONY i to nie jest ostrożność na zapas. Digest to
         * jedyna poczta w tym serwisie, która wychodzi BEZ czynności
         * człowieka bezpośrednio przed wysyłką, więc pomyłka w danych albo
         * w treści rozchodzi się od razu do wszystkich zapisanych — i nie da
         * się jej cofnąć. Właściciel włącza go świadomie, po sprawdzeniu
         * listu na własnej skrzynce (`--na-sucho` i `--tylko=<nazwa>`).
         */
        'wlaczony' => (bool) env('KUKING_DIGEST_WLACZONY', false),

        /*
         * DOBOWY SUFIT PODSUMOWAŃ — sześćdziesiąt listów, nie sto dwadzieścia.
         *
         * Liczba bierze się z rachunku, nie z wyczucia. Całe wiadro to 300
         * listów na dobę na CAŁY serwis (patrz sekcja `poczta` wyżej), a dwie
         * inne rzeczy mają w nim pierwszeństwo:
         *
         *   120  logowanie linkiem e-mail (issue #25) — dla części osób jest
         *        to JEDYNA droga wejścia, więc nie wolno jej przyhamować
         *        biuletynem;
         *   100  rezerwa na potwierdzenia rejestracji i przypomnienia haseł,
         *        których nie da się przełożyć na jutro.
         *
         * Zostaje 80, z czego bierzemy 60 i pozostawiamy 20 zapasu. Kierunek
         * pomyłki jest tu wybrany świadomie: podsumowanie, które nie doszło,
         * jest niczym — potwierdzenie rejestracji, które nie doszło, kończy
         * komuś przygodę z serwisem, zanim się zaczęła (`docs/decyzje/
         * POCZTA.md` §5 pkt 4). W tygodniu fali z Garnek.pl to rejestracje
         * mają wygrać, nie biuletyn.
         *
         * PRZEPUSTOWOŚĆ TYGODNIOWA = 60 × 7 = **420 osób**. Powyżej tego
         * progu plan darmowy przestaje wystarczać: część ludzi dostawałaby
         * list co drugi tydzień, a obietnica „raz w tygodniu" byłaby wtedy
         * nieprawdą po drugiej stronie. Rachunek dla 100, 500 i 2 000 kont
         * i moment przejścia na plan płatny: `docs/DECISIONS.md` D-057.
         *
         * SUFIT PILNUJE `App\Domain\Security\DziennyBudzetListow` — ta sama
         * klasa co przy logowaniu linkiem, z własnym kluczem licznika. Dwa
         * własne liczniki tej samej rzeczy rozjechałyby się przy pierwszej
         * zmianie którejkolwiek z tych liczb.
         */
        'dzienny_limit' => (int) env('KUKING_DIGEST_DZIENNY_LIMIT', 60),

        /*
         * NAJKRÓTSZY ODSTĘP MIĘDZY DWOMA LISTAMI DO TEJ SAMEJ OSOBY.
         *
         * „Jeden e-mail tygodniowo. Nigdy więcej" (issue #11 pkt 4) jest
         * OBIETNICĄ, nie preferencją, więc pilnuje jej liczba w konfiguracji,
         * a nie pamięć piszącego. Siedem dni, nie sześć: sześć pozwoliłoby
         * na dwa listy w jednym tygodniu kalendarzowym.
         *
         * Ta sama liczba jest zabezpieczeniem przed dwukrotnym uruchomieniem
         * zadania w ciągu doby — patrz `OdbiorcyDigestu`.
         */
        'odstep_dni' => (int) env('KUKING_DIGEST_ODSTEP_DNI', 7),

        /*
         * OKNO, Z KTÓREGO BIERZEMY TREŚĆ. Tyle samo co odstęp: list ma
         * opowiedzieć tydzień, którego adresat nie widział.
         */
        'okno_dni' => (int) env('KUKING_DIGEST_OKNO_DNI', 7),

        /*
         * ODSTĘP MIĘDZY KOLEJNYMI LISTAMI W KOLEJCE, W SEKUNDACH.
         *
         * `docs/decyzje/POCZTA.md` §5 pkt 5: „rozłóż na godziny — 10 000
         * wiadomości w 60 sekund to sygnał spamowy". 20 s × 120 listów to
         * około 40 minut wysyłki, czyli tempo nie do odróżnienia od zwykłego
         * ruchu transakcyjnego, a jednocześnie wszystko dochodzi tego samego
         * przedpołudnia.
         */
        'odstep_sekund' => (int) env('KUKING_DIGEST_ODSTEP_SEKUND', 20),

        // Ile pozycji najwyżej pokazujemy w każdej sekcji listu. Trzy, bo
        // więcej zamienia list w katalog (`docs/product/RETENTION_LOOPS.md`
        // §4.3: „Maks. 3 cudze treści").
        'max_pozycji' => (int) env('KUKING_DIGEST_MAX_POZYCJI', 3),

        /*
         * PYTANIE OD GOSPODARZA — jedno zdanie na końcu listu.
         *
         * W konfiguracji, nie w szablonie, bo to jest jedyna część listu,
         * którą właściciel ma zmieniać co tydzień — bez wdrożenia i bez
         * dotykania kodu. Pusta wartość usuwa cały akapit z listu (i z wersji
         * tekstowej), zamiast zostawić pusty nagłówek.
         */
        'pytanie' => env(
            'KUKING_DIGEST_PYTANIE',
            'Czy gotujesz w tym tygodniu coś, czego nikt u nas jeszcze nie pokazał? Napisz, jestem ciekawa.',
        ),
    ],

    'zgody' => [
        /*
         * WERSJA POLITYKI PRYWATNOŚCI zapisywana przy każdym zdarzeniu zgody
         * (`dziennik_zgod.wersja_polityki`, D-072).
         *
         * PO CO: dowód „zgodził się 12 września" nie mówi, NA CO — a to jest
         * pierwsze pytanie przy sporze o ZAKRES zgody. Ta wartość wiąże wiersz
         * dziennika z konkretnym brzmieniem dokumentu, który człowiek wtedy
         * mógł przeczytać.
         *
         * DATA STANU DOKUMENTU, NIE WYMYŚLONY NUMER WYDANIA. Polityka
         * prywatności (`resources/legal/polityka-prywatnosci.md`) nie ma
         * numeracji — ma w nagłówku zdanie „opisuje stan serwisu na <data>".
         * Osobny numer („v2") dałby dwie prawdy o tym samym dokumencie,
         * a jedna z nich rozjechałaby się przy pierwszej poprawce, której
         * nikt by tu nie odnotował.
         *
         * PODBIJANE RĘCZNIE, RAZEM ZE ZDANIEM W NAGŁÓWKU DOKUMENTU — i tak
         * samo jak `wersja.etykieta` niżej trzymane w repozytorium, NIE
         * w zmiennej środowiskowej: zmiana wersji dokumentu prawnego ma
         * przechodzić przez recenzję jak każda inna zmiana, a nie dać się
         * przestawić w panelu Railwaya.
         */
        'wersja_polityki' => '2026-09-10',
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

        /*
         * CLOUDFLARE WEB ANALYTICS — jedyna zewnętrzna analityka w tym
         * serwisie (D-092).
         *
         * PO CO W OGÓLE, SKORO MAMY `App\Domain\Analytics\*`
         * Bo to są dwa różne pytania. Nasza analityka serwerowa odpowiada na
         * „ile osób ugotowało w tym tygodniu" — liczy zdarzenia, które
         * powstają W BAZIE, więc o kimś, kto wszedł na stronę powitalną
         * i wyszedł, nie wie NIC. Beacon Cloudflare odpowiada na drugie
         * pytanie, którego z Postgresa zadać się nie da: skąd ludzie
         * przychodzą i które strony oglądają, ZANIM cokolwiek u nas zrobią.
         * Jedno nie zastępuje drugiego i nic z `App\Domain\Analytics\*`
         * nie znika.
         *
         * DLACZEGO CLOUDFLARE, A NIE PLAUSIBLE (który stał tu przez pół dnia)
         * Decyzja właściciela i rozstrzygnęła cena: Plausible to 9 € miesięcznie
         * za odpowiedź na dwa pytania, a Cloudflare już przetwarza KAŻDE
         * żądanie do kuking.pl, bo jest naszym CDN-em i WAF-em przed Railwayem
         * (`docs/infra/INFRA_DECISION.md`). Włączenie jego analityki nie wysyła
         * mu ani jednego nowego bajta i nie dokłada dostawcy do polityki
         * prywatności — Cloudflare, Inc. stoi tam od Turnstile'a (D-050).
         *
         * PUSTY TOKEN = SKRYPTU NIE MA W HTML-U W OGÓLE. To jest stan
         * domyślny lokalnie, w testach i w CI — dokładnie ten sam wzorzec
         * co puste klucze Turnstile (D-050). Nie ma osobnej flagi „włącz
         * analitykę" obok tokenu, bo dałaby stan „włączone, ale bez tokenu",
         * czyli skrypt wysyłający zdarzenia donikąd — narzędzie meldujące
         * sukces, nie robiąc nic (patrz `App\Support\Turnstile`).
         */
        'cloudflare' => [
            /*
             * Token serwisu z panelu Cloudflare (Web Analytics → Add a site).
             * Puste = analityki nie ma.
             *
             * Beacon identyfikuje serwis TOKENEM, a nie nazwą domeny — to
             * jedyna różnica w konfiguracji względem Plausible, które
             * potrzebowało domeny i hosta. Token nie jest sekretem: stoi
             * w HTML-u każdej strony i tak ma być.
             */
            'token' => trim((string) env('CLOUDFLARE_ANALYTICS_TOKEN', '')),

            /*
             * DWA RÓŻNE HOSTY, I TO NIE JEST LITERÓWKA.
             *
             * Zmierzone przez pobranie i odczytanie `beacon.min.js`
             * (D-092), a nie przepisane z dokumentacji dostawcy:
             *
             *   - plik pobiera się z `static.cloudflareinsights.com`
             *     → dyrektywa `script-src`,
             *   - zdarzenia lecą na `cloudflareinsights.com/cdn-cgi/rum`,
             *     czyli na host BEZ `static.` → dyrektywa `connect-src`.
             *
             * Przy Plausible oba adresy były tym samym hostem, więc jedna
             * wartość obsługiwała obie dyrektywy CSP. Tutaj nie obsługuje,
             * i dopisanie tylko pierwszego daje stronę bez usterki, pusty
             * panel i zero śladu w dzienniku.
             *
             * Adresy stoją TUTAJ, a nie w `env()`, bo Cloudflare Web
             * Analytics nie ma wariantu samodzielnie hostowanego — nie ma
             * czego przenosić, a zmienna środowiskowa sugerowałaby, że jest.
             * Widok i reguła CSP liczą je z tego miejsca przez
             * `App\Support\AnalitykaCloudflare` i NIE powtarzają literałów:
             * powtórzenie w dwóch miejscach gwarantuje, że przy zmianie
             * jedno zostanie w tyle i skrypt zostanie po cichu zablokowany
             * przez politykę bezpieczeństwa — bez śladu na ekranie.
             */
            'host_skryptu' => 'https://static.cloudflareinsights.com',
            'host_zdarzen' => 'https://cloudflareinsights.com',

            /*
             * GDZIE STOI OBIETNICA, KTÓREJ PILNUJE `/health`.
             *
             * Analityka jest jedyną rzeczą w tym serwisie, o której zdanie
             * w oznajmującym czasie teraźniejszym stoi w DOKUMENCIE PRAWNYM:
             * polityka prywatności mówi czytelnikowi wprost, że statystykę
             * odwiedzin prowadzi Cloudflare Web Analytics, i mówi nawet od
             * kiedy. Turnstile widać po formularzu, przycisk Google i
             * Facebooka widać albo nie widać na ekranie logowania; tego nie
             * widać nigdzie. Pusty `CLOUDFLARE_ANALYTICS_TOKEN` na produkcji
             * znaczy więc dokładnie tyle, że dokument prawny opisuje
             * przetwarzanie, którego nie ma — i nie ma ani jednego miejsca,
             * w którym ktokolwiek by to zauważył.
             *
             * Dlatego `HealthController::sprawdzAnalityke()` pyta o ROZJAZD,
             * a nie o sam brak tokenu: sygnał zapala się wtedy i tylko wtedy,
             * gdy poniższy dokument nadal obiecuje analitykę, a tokenu nie ma.
             * Uciszyć go można DWOMA uczciwymi sposobami — wpisać token albo
             * wykreślić obietnicę z polityki. Trzeciego (przełącznika „nie
             * krzycz") świadomie nie ma: przy Google i Facebooku wyłącznik
             * znaczy „nie chcę tej drogi" i nikogo nie okłamuje, a tutaj
             * znaczyłby „niech dokument prawny dalej mówi nieprawdę, tylko
             * po cichu".
             *
             * DLACZEGO ŚCIEŻKA I FRAZA SIEDZĄ W KONFIGURACJI, A NIE W KODZIE
             * Bo to są dwie rzeczy, które trzeba móc podmienić w teście —
             * inaczej nie da się sprawdzić gałęzi „dokument już nic nie
             * obiecuje, więc `/health` milczy", a niesprawdzona gałąź
             * strażnika jest gałęzią, o której nie wiadomo, czy działa.
             *
             * Fraza jest ta sama, której w polityce szuka
             * `PolitykaPrywatnosciWymieniaKazdaUslugeTest` — PEŁNA nazwa
             * usługi, nie samo „Cloudflare", które stoi w tym dokumencie od
             * Turnstile'a i od R2.
             */
            'obietnica' => [
                // Ścieżka względem `resource_path()`.
                'dokument' => 'legal/polityka-prywatnosci.md',
                'fraza' => 'Cloudflare Web Analytics',
            ],
        ],
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

        // ILE DNI ROBOCZYCH PRZED TERMINEM ODPOWIEDZI WYSŁAĆ LIST (D-060).
        //
        // Nowe odwołanie daje powiadomienie w panelu i licznik przy pozycji
        // „Odwołania" — poczty na każde odwołanie NIE ma i mieć nie będzie
        // (uzasadnienie: D-060, `PowiadomOOdwolaniu`). Pocztą jedzie
        // wyłącznie sytuacja, w której termin z DSA art. 20 jest BLISKO
        // albo już MINĄŁ, a sprawy nikt nie zamknął — bo wtedy powiadomienie
        // w serwisie, którego nikt nie przeczytał, właśnie zawiodło.
        //
        // Zero wyłącza sam próg „blisko" i zostawia listy tylko dla spraw PO
        // terminie; to jest poprawna, świadoma konfiguracja, a nie wyłączenie
        // pilnowania.
        //
        // KOSZT W WIADRZE POCZTY: najwyżej JEDEN list na dobę, i tylko
        // w dniach, w których naprawdę coś wisi. Dlatego ta funkcja NIE ma
        // własnego sufitu w podziale wiadra (sekcja `poczta` niżej) —
        // mieści się w rezerwie transakcyjnej, tak jak przypomnienie hasła.
        // Sufity są dla funkcji, które wysyłają WIELE listów naraz.
        'appeal_reminder_working_days' => (int) env('KUKING_APPEAL_REMINDER_DAYS', 2),

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

        /*
         * SYGNAŁY AUTOMATU (D-052) — progi wykrywacza podejrzanych treści.
         *
         * Automat oznacza treść DO PRZEGLĄDU i nic więcej: nie ukrywa, nie
         * ogranicza zasięgu, nie powiadamia autora (pozycje 3.6, 3.10, 3.14
         * i 3.16 z `docs/INSPIRATION_DECISIONS.md`). Progi stoją TUTAJ,
         * a nie w bazie z ekranem do klikania — reguła w bazie jest
         * nietestowalna (`docs/research/repos/discourse-discourse.md` §7).
         *
         * Pełne uzasadnienie każdego progu wraz z fałszywymi alarmami,
         * których się po nim spodziewamy: `docs/legal/SYGNALY_AUTOMATU.md`.
         */
        'sygnaly' => [
            // JEDEN WYŁĄCZNIK NA CAŁOŚĆ. `KUKING_SYGNALY_AUTOMATU=false`
            // zatrzymuje analizę u źródła: zadanie w kolejce kończy się od
            // razu i nie powstaje ani jeden nowy wiersz. Nic już istniejącego
            // nie znika — oznaczenia z kolejki zostają do rozpatrzenia.
            'wlaczone' => (bool) env('KUKING_SYGNALY_AUTOMATU', true),

            // POWTÓRZONA TREŚĆ — okno czasowe i próg podobieństwa.
            //
            // 60 minut, bo mowa o wysłaniu tego samego kilka razy pod rząd,
            // a nie o wracaniu do tematu po tygodniu. Ten sam przepis
            // opublikowany ponownie w listopadzie to normalne życie.
            'powtorzenie_minut' => (int) env('KUKING_SYGNAL_POWTORZENIE_MINUT', 60),

            // 40 znaków — poniżej tej długości powtórzenia to uprzejmości
            // („Wygląda pysznie", „Dzięki za przepis"), a nie spam. To jest
            // najważniejszy z tych progów: bez niego automat oznaczałby
            // najżyczliwsze osoby w serwisie.
            'powtorzenie_min_znakow' => (int) env('KUKING_SYGNAL_POWTORZENIE_ZNAKOW', 40),

            // 92% podobieństwa — łapie „prawie identyczną" treść (podmieniony
            // adres, dopisane imię), a nie dwa przepisy na to samo ciasto.
            'powtorzenie_podobienstwo' => (float) env('KUKING_SYGNAL_POWTORZENIE_PODOBIENSTWO', 0.92),

            // Ile najnowszych treści autora bierzemy do porównania. Twardy
            // sufit, żeby jedno zadanie w kolejce nie rosło razem z tabelą:
            // przy hurtowym przenoszeniu archiwum z innego serwisu jedna
            // osoba wrzuca dziesiątki wpisów w godzinę.
            'powtorzenie_limit_porownan' => (int) env('KUKING_SYGNAL_POWTORZENIE_LIMIT', 20),

            // ODNOŚNIK U ŚWIEŻEGO KONTA — dwa warunki naraz, nie jeden.
            //
            // Konto młodsze niż 7 dni ORAZ treść w pierwszej trójce tego, co
            // opublikowało. Samo „nowe konto" oznaczałoby całą falę osób
            // przechodzących do nas grupą; sam „odnośnik" oznaczałby każdą
            // osobę, która podaje źródło przepisu.
            'swieze_konto_dni' => (int) env('KUKING_SYGNAL_SWIEZE_KONTO_DNI', 7),
            'swieze_konto_tresci' => (int) env('KUKING_SYGNAL_SWIEZE_KONTO_TRESCI', 3),

            /*
             * DOMENY, KTÓRYCH NIE LICZYMY JAKO „ODNOŚNIK ZEWNĘTRZNY".
             *
             * Serwisy, Z KTÓRYCH ludzie do nas przychodzą. Osoba przenosząca
             * się z Garnka wkleja w pierwszym wpisie odnośnik do swojego
             * starego profilu — to jest dokładnie ta osoba, dla której ten
             * serwis powstał, i najgorszy możliwy moment, żeby ją oznaczyć.
             *
             * Lista jest KRÓTKA i konkretna. To NIE jest „lista zaufanych
             * stron" — nie ma tu Facebooka ani YouTube'a, bo tamte adresy
             * pojawiają się w spamie równie często jak w dobrej wierze.
             */
            'domeny_bez_sygnalu' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('KUKING_SYGNAL_DOMENY_BEZ_SYGNALU', 'garnek.pl,kuking.pl')),
            ))),
        ],

        /*
         * DRUGA PARA OCZU: MODEL OCENIAJĄCY TREŚĆ (D-055).
         *
         * OpenAI `omni-moderation-latest` — bezpłatne API oceniające TEKST
         * i OBRAZY pod kątem nienawiści, przemocy, treści seksualnych
         * i samookaleczenia.
         *
         * TO ŁAPIE INNĄ KLASĘ TREŚCI NIŻ SYGNAŁY WYŻEJ. Tamte szukają spamu
         * („zarobki z domu", odnośniki, numery telefonu); model spamu nie
         * ocenia w ogóle. To jest UZUPEŁNIENIE, nie zamiennik — i trzeba to
         * napisać wprost, bo inaczej ktoś uzna, że skoro jest AI, to spam
         * mamy załatwiony.
         *
         * GRANICA TA SAMA CO WYŻEJ, TYLKO WAŻNIEJSZA: model podnosi rękę,
         * nigdy nie zamyka drzwi. Wynik nie ukrywa treści, nie ogranicza
         * zasięgu i nie dociera do autora — kończy się pozycją w kolejce
         * z powodem po polsku. Model uczony głównie na angielszczyźnie będzie
         * się mylił na polskim, a już zwłaszcza na języku, jakim mówi
         * o jedzeniu siedemdziesięcioletnia kobieta z Podkarpacia.
         *
         * Pełny projekt, podstawa prawna i granice:
         * `docs/legal/SYGNALY_AUTOMATU.md` §8.
         */
        'model' => [
            /*
             * BRAK KLUCZA = FUNKCJA WYŁĄCZONA I NIC NIE PADA.
             *
             * Tak jest lokalnie, w CI i w testach — żadne żądanie nie wychodzi,
             * `OcenaModelem` oddaje pustą listę sygnałów, publikacja wpisu
             * dzieje się dokładnie tak jak przedtem. To jest ten sam wzorzec,
             * co puste klucze Turnstile (D-050).
             */
            'klucz' => env('OPENAI_MODERATION_KEY'),

            'endpoint' => env('KUKING_MODEL_ENDPOINT', 'https://api.openai.com/v1/moderations'),
            'nazwa' => env('KUKING_MODEL_NAZWA', 'omni-moderation-latest'),

            // Krótko celowo: cudza usługa nie stoi w drodze publikacji (ta
            // dzieje się w innym żądaniu), ale nie może też blokować workera
            // kolejki na minuty przy awarii po tamtej stronie.
            'limit_czasu' => (int) env('KUKING_MODEL_LIMIT_CZASU', 8),

            /*
             * PRÓG WYNIKU, OD KTÓREGO STAWIAMY POZYCJĘ W KOLEJCE.
             *
             * API oddaje osobno `flagged` (własna decyzja modelu) i wyniki
             * liczbowe per kategoria. Nie bierzemy samego `flagged`, bo przy
             * polszczyźnie i kuchni („zabiłam kurę", „krwisty stek") jest
             * hojne — a każde trafienie kosztuje uwagę jedynego moderatora.
             *
             * 0,5 to punkt, poniżej którego model sam nie jest przekonany.
             * Zmiana tej liczby to zmiana liczby pozycji dziennie — patrz
             * `kuking:raport-sygnalow`.
             */
            'prog' => (float) env('KUKING_MODEL_PROG', 0.5),

            /*
             * NIŻSZY PRÓG DLA SPRAW, KTÓRE NIE MOGĄ CZEKAĆ.
             *
             * Kategorie z `KategorieModeracji::PILNE` mają w
             * `resources/legal/zasady.md` własną sekcję „Czego nie tolerujemy
             * w ogóle". Tu wolimy fałszywy alarm od przeoczenia, więc próg
             * jest niższy niż zwykły.
             */
            'prog_pilny' => (float) env('KUKING_MODEL_PROG_PILNY', 0.2),

            // Czy oceniać ZDJĘCIA. To jest największa wartość tej funkcji:
            // fotografia obiadu od nieznajomego to jedyna treść, której nikt
            // nie przeczyta, dopóki ktoś jej nie zgłosi.
            'ocenia_zdjecia' => (bool) env('KUKING_MODEL_OCENIA_ZDJECIA', true),

            // Ile zdjęć z jednego wpisu wysyłamy do oceny. Wpis ma najwyżej
            // sześć; oceniamy pierwsze dwa, bo koszt to dwa żądania HTTP na
            // wpis, a spam ze zdjęciem prawie nigdy nie chowa go na końcu.
            'zdjec_na_wpis' => (int) env('KUKING_MODEL_ZDJEC_NA_WPIS', 2),

            /*
             * ADRES, NA KTÓRY IDZIE ALARM. Pusty = bez poczty (zostaje sama
             * kolejka w panelu).
             *
             * LIMIT POCZTY JEST TU REGUŁĄ PROJEKTOWĄ, NIE DROBIAZGIEM:
             * EmailLabs na planie darmowym daje 300 listów dziennie, dzielone
             * z potwierdzeniami rejestracji. Dlatego listy natychmiastowe idą
             * WYŁĄCZNIE dla kategorii pilnych, a reszta raz dziennie, jednym
             * podsumowaniem (`kuking:podsumowanie-automatu`).
             */
            'alarm_email' => env('KUKING_MODEL_ALARM_EMAIL'),
        ],
    ],

    'wersja' => [
        // ETAP PRODUKTU — podbijany RĘCZNIE. Trzymany w repo, nie w zmiennej
        // środowiskowej, żeby zmiana wersji przechodziła przez recenzję jak
        // każda inna.
        //
        // SŁOWO zmienia się przy kamieniach milowych z docs/ROADMAP.md:
        // „Alfa 0.N" do czasu zamkniętej alfy (D-012), potem „Beta 0.N",
        // potem 1.0. Bez SemVera — nie wydajemy biblioteki, której ktoś
        // pilnuje zgodności API, tylko serwis dla ludzi.
        //
        // CYFRA ROŚNIE PRZY KAŻDEJ ZMIANIE, KTÓRĄ CZŁOWIEK ZOBACZY: nowy
        // ekran, zmieniony układ, nowa funkcja, inne zachowanie formularza.
        // Poprawki bez śladu w interfejsie (testy, refaktor, dokumentacja)
        // jej NIE ruszają.
        //
        // DLACZEGO TA REGUŁA W OGÓLE TU STOI. Do 11 września 2026 reguła
        // mówiła tylko, kiedy zmienia się SŁOWO — i przez to cyfra nie
        // ruszyła się ani razu od pierwszego dnia, mimo kilkunastu scaleń
        // dziennie. Numer, którego nikt nigdy nie podbija, nie niesie żadnej
        // informacji: prawdę o tym, co działa, mówił wyłącznie skrót commita
        // obok. Zgłoszenie właściciela, decyzja właściciela.
        //
        // KAŻDY PODBICIE CYFRY MA WPIS W `CHANGELOG.md` — jedno pilnuje
        // drugiego. Wersja bez wpisu jest numerem bez treści, a wpis bez
        // wersji nie da się z niczym powiązać.
        'etykieta' => 'Alfa 0.22',

        // CO DOKŁADNIE JEST WDROŻONE — ustawiane samo, przez Railway.
        //
        // Do 10 września 2026 stało tu, że ta sama wartość idzie do
        // SENTRY_RELEASE, „więc wersja w stopce i wersja przy błędzie w Sentry
        // to ten sam commit". Nieprawda: Sentry'ego w projekcie nie ma (D-041),
        // więc nie ma też żadnych wpisów, z którymi ten skrót miałby się
        // wiązać. Powód, dla którego skrót tu stoi, jest prostszy i prawdziwy:
        // ktoś zgłasza usterkę, przepisuje to, co widzi w stopce, i wiadomo,
        // którego commita dotyczy zgłoszenie. Pełne wyjaśnienie w komentarzu
        // klasy `App\Support\Wersja`.
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
