<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trzeci człon reguły z `AGENTS.md` §6 dostaje strażnika.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * „Zmiana schematu = migracja + test + `docs/DATABASE.md` + rollback".
 * Migracji pilnuje CI, testu pilnuje przegląd, rollbacku pilnują strażniki
 * `down()` z D-088 — a trzeciego członu do 12 września 2026 nie pilnowało
 * NIC powyżej poziomu kolumny tekstowej (`DokumentacjaBazyOpisujeSchematTest`).
 *
 * Zmierzone tego dnia na pełnym schemacie: **50 tabel w bazie, 42 nasze,
 * 41 opisanych, jedna nieopisana ani jednym słowem** — `cooked_event_media`.
 * Nie jest to tabela martwa: trzyma zdjęcia z gotowania, stoi na OBU listach
 * odwołań do `media` (`KasujZdjecie::ODWOLANIA`, `DostepDoZdjecia::ODWOLANIA`)
 * i jej kaskada była powodem osobnej poprawki (issue #285, D-083). Ktoś
 * szukający w `docs/DATABASE.md` odpowiedzi na pytanie „co się stanie ze
 * zdjęciem, gdy skasuję wykonanie" nie znajdował tam ani słowa — a dokument
 * wyglądał na kompletny, bo 41 innych tabel w nim było. **Dokument, który
 * rozjechał się z bazą, jest gorszy niż brak dokumentu: wygląda na sprawdzony
 * i zatrzymuje szukanie.**
 *
 * GDZIE POSTAWIONA JEST GRANICA I DLACZEGO AKURAT TAM
 * Ten test pilnuje **poziomu TABELI, w obie strony** — i ani jednego piętra
 * niżej. Wybór nie jest wygodą, tylko odpowiedzią na to, co strażnika psuje:
 *
 *  - **Kolumna co do typu = codzienny hałas.** Migracja dokładająca
 *    `position smallint` albo przetypowująca `varchar(120)` na `varchar(240)`
 *    oblewałaby test, który o tej zmianie nie ma nic do powiedzenia. Taki
 *    strażnik zostaje wyłączony w tydzień, a strażnik, którego się wyłącza,
 *    nie jest strażnikiem.
 *  - **Sama lista tabel bez kierunku odwrotnego przepuszcza drugą połowę
 *    rozjazdu** — dokument opisujący tabelę, której już nie ma. To jest
 *    dokładnie ten wpis, który zatrzymuje szukanie najskuteczniej, bo czyta
 *    się go jako opis stanu.
 *  - **Tabela to jedyny obiekt schematu, o którym dokument MUSI mieć własną
 *    sekcję.** Kolumny bywają opisane zbiorczo, indeksy w bloku SQL, CHECK-i
 *    w zdaniu przy kolumnie — ale tabela bez nagłówka znaczy, że nikt jej
 *    nigdy nie opisał, i nie da się tego pomylić z inną formą opisu.
 *  - **Częstotliwość.** Nowa tabela to jedna migracja na kilkanaście; nowa
 *    kolumna — kilka na tydzień. Strażnik odzywa się więc rzadko i za każdym
 *    razem ma rację.
 *
 * Piętro kolumn NIE jest przez to bez opieki: `DokumentacjaBazyOpisujeSchematTest`
 * bierze wszystkie kolumny TEKSTOWE (dziś 129) i wymaga, żeby dokument je
 * nazywał. Te dwa testy dzielą się pracą, a nie dublują: tamten pyta „czy ta
 * kolumna jest nazwana gdziekolwiek", ten — „czy ta tabela ma własną sekcję".
 *
 * DWIE TWARDE REGUŁY SCHEMATU PRZY OKAZJI
 * Dwa ostatnie testy w tym pliku nie dotyczą dokumentu, tylko samej bazy,
 * i stoją tu, bo pilnują rzeczy, które w tym projekcie są twarde:
 *
 *  1. każdy klucz obcy ma ZAPISANE zachowanie przy kasowaniu;
 *  2. adres e-mail i nazwa użytkownika są unikalne bez względu na wielkość liter.
 *
 * Obie są opisane w `docs/DATABASE.md`, w sekcji „Dwie reguły, które
 * obowiązują CAŁY schemat" — i to jest jedyny powód, dla którego mieszczą się
 * w jednym pliku z testami dokumentu.
 *
 * SKĄD BIERZE SIĘ SCHEMAT
 * Z `pg_catalog` i `information_schema` ŻYWEJ bazy testowej, po wykonaniu
 * migracji — nie z czytania plików migracji. Liczy się stan końcowy: kolumna
 * dodana, przetypowana i usunięta trzema migracjami nie istnieje, a test
 * czytający pliki wymagałby jej opisu.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE
 *  - **Czy sekcja tabeli mówi PRAWDĘ.** Sprawdzamy, że sekcja JEST. Prawdy
 *    o danych nie da się wyprowadzić ze schematu — ta sama granica stoi przy
 *    `TabelaStackuMowiPrawdeTest` i przy `DokumentacjaBazyOpisujeSchematTest`.
 *  - **Wzmianek o tabeli w tekście bieżącym.** Liczy się NAGŁÓWEK. Tabela
 *    wymieniona w zdaniu przy innej tabeli nie jest opisana, tylko wspomniana,
 *    a dokument świadomie wspomina też rzeczy USUNIĘTE i ODRZUCONE
 *    (`users.google_sub`, `posts.topic_id`) oraz PLANOWANE (sekcja „V1 / V2":
 *    `groups`, `meal_plans`, `shopping_lists`). Gdyby liczyła się wzmianka,
 *    ta sekcja byłaby listą tabel „opisanych, a nieistniejących".
 *  - **Widoków, sekwencji i typów.** Dziś schemat nie ma ani jednego widoku;
 *    gdy będzie miał, to osobna sprawa i osobna naprawa.
 */
class SchematBazyTrzymaSieDokumentuTest extends TestCase
{
    use RefreshDatabase;

    private const DOKUMENT = 'docs/DATABASE.md';

    /**
     * Tabele poza obowiązkiem opisu — **z powodem przy każdej**.
     *
     * Wszystkie siedem zakłada Laravel swoimi migracjami z `0001_01_01_*`
     * i żadnej z nich nie projektowaliśmy. `docs/DATABASE.md` nosi nagłówek
     * „Model danych" i opisuje NASZE dane.
     *
     * Lista jest ta sama co w `DokumentacjaBazyOpisujeSchematTest` i ma taka
     * zostać — dwie różne listy wyłączeń rozjechałyby się przy pierwszej
     * nowej tabeli frameworka. Dopisanie do niej NASZEJ tabeli to wyłączanie
     * tego testu, a nie sprzątanie; od tego jest próg MIN_TABEL_W_ZAKRESIE.
     *
     * `sessions` BYŁA na tej liście do 21.09.2026 i została z niej ZDJĘTA.
     * Tabelę rzeczywiście zakłada Laravel, ale leży w niej para
     * (`user_id`, adres IP, `User-Agent`) — NASZE dane osobowe, nie techniczne
     * wnętrzności frameworka. Badanie RZ-01 ustaliło, że sześć kolejnych
     * audytów prywatności przeoczyło tę tabelę, i nazwało przyczynę: nie było
     * jej w `docs/DATABASE.md`, bo nikt jej nie „dodawał", więc nikt nie
     * przeszedł ścieżki „migracja + test + dokument". Wyjątek dla sterownika
     * frameworka kosztował tu dokładnie to, przed czym ta lista miała chronić.
     * Kryterium jest więc „czyje to dane", a nie „kto napisał migrację".
     */
    private const TABELE_FRAMEWORKA = [
        'migrations',            // rejestr wykonanych migracji, prowadzi go Laravel
        'cache',                 // sterownik cache'a
        'cache_locks',           // sterownik cache'a
        'jobs',                  // kolejka zadań
        'job_batches',           // kolejka zadań
        'failed_jobs',           // kolejka zadań
        'password_reset_tokens', // wbudowane resetowanie hasła
    ];

    /**
     * Klucze obce, które ŚWIADOMIE nie mają klauzuli `ON DELETE`.
     *
     * Klucz bez klauzuli nie jest kluczem bez zachowania — SQL daje mu
     * `NO ACTION`. Cała różnica jest w tym, czy ktoś tę odmowę WYBRAŁ, czy
     * tylko jej nie napisał, a w `\d` tabeli jedno i drugie wygląda tak samo.
     * Ta lista jest miejscem, w którym wybór zostaje zapisany.
     *
     * Wyjątek nie zgnije po cichu: `test_lista_wyjatkow_od_on_delete_nie_zgnila`
     * sprawdza, że każdy wpis stąd nadal istnieje w schemacie i nadal nie ma
     * klauzuli. Gdy `tags.merged_into_tag_id` kiedyś dostanie jawne
     * `ON DELETE`, test każe wykreślić wpis, zamiast trzymać martwy wyjątek.
     *
     * @var array<string, string> nazwa constraintu => powód
     */
    private const KLUCZE_SWIADOMIE_BEZ_ON_DELETE = [
        // `tags.merged_into_tag_id` → `tags`. Domyślne `NO ACTION` Postgresa
        // BLOKUJE skasowanie tagu kanonicznego, dopóki są do niego przypięte
        // tagi scalone — to jest reguła z R1 §3 egzekwowana w bazie, a nie
        // w PHP. Pełne uzasadnienie: komentarz przy kolumnie w migracji
        // `2026_09_07_100000_create_tags_tables` i sekcja „Dwie reguły, które
        // obowiązują CAŁY schemat" w docs/DATABASE.md.
        'tags_merged_into_tag_id_foreign' => 'blokada skasowania tagu kanonicznego (R1 §3)',
    ];

    /**
     * Kolumny, po których człowiek WRACA DO WŁASNEGO KONTA.
     *
     * Tylko te dwie. Nie „każda kolumna tekstowa z UNIQUE": duplikat tutaj
     * nie jest brzydkim wierszem w tabeli, tylko drugim kontem tej samej osoby
     * albo cudzym profilem pod adresem, który ktoś rozdał znajomym.
     *
     * @var list<array{0: string, 1: string, 2: string}> tabela, kolumna, oczekiwana nazwa indeksu
     */
    private const UNIKALNE_BEZ_WIELKOSCI_LITER = [
        ['users', 'email', 'users_email_lower_unique'],
        ['profiles', 'username', 'profiles_username_lower_unique'],
    ];

    /**
     * PROGI — bez nich każdy test w tym pliku jest zielony na zawsze.
     *
     * Test porównujący dwa zbiory przechodzi także wtedy, gdy porównał zero
     * pozycji: różnica pustych zbiorów jest pusta (`docs/PULAPKI_TESTOW.md`,
     * pułapka 2). Zła nazwa schematu, literówka w zapytaniu, odczyt z bazy bez
     * migracji albo przeniesiony dokument mają tu OBLAĆ — i to z komunikatem
     * mówiącym, że zepsuł się TEST, a nie schemat.
     *
     * Liczby zmierzone 12.09.2026, progi z zapasem:
     *
     *  - MIN_TABEL_W_BAZIE = 40 (dziś 50).
     *  - MIN_TABEL_W_ZAKRESIE = 35 (dziś 43: 50 minus 7 frameworkowych;
     *    było 42 minus 8, zanim `sessions` zeszła z listy wyłączeń).
     *    To zamek na jedynym wytrychu, jaki ta konstrukcja ma — wpisaniu
     *    naszych tabel do TABELE_FRAMEWORKA, żeby test zamilkł.
     *  - MIN_NAGLOWKOW_TABEL = 35 (dziś 42). Pilnuje ODCZYTU dokumentu:
     *    zmiana stylu nagłówków albo przeniesienie pliku zgłosiłoby inaczej
     *    wszystkie 42 tabele naraz jako nieopisane.
     *  - MIN_KLUCZY_OBCYCH = 60 (dziś 71).
     *  - MIN_INDEKSOW_UNIKALNYCH = 60 (dziś 92).
     *  - MIN_DLUGOSC_DOKUMENTU = 50000 znaków (dziś ok. 205000).
     */
    private const MIN_TABEL_W_BAZIE = 40;

    private const MIN_TABEL_W_ZAKRESIE = 35;

    private const MIN_NAGLOWKOW_TABEL = 35;

    private const MIN_KLUCZY_OBCYCH = 60;

    private const MIN_INDEKSOW_UNIKALNYCH = 60;

    private const MIN_DLUGOSC_DOKUMENTU = 50000;

    // ---------------------------------------------------------------
    // 1. Dokument wobec schematu — w obie strony
    // ---------------------------------------------------------------

    public function test_kazda_nasza_tabela_ma_wlasna_sekcje_w_dokumencie(): void
    {
        $tabele = $this->tabeleWZakresie();
        $opisane = $this->tabeleOpisaneWDokumencie();

        $this->assertProgiSkanu($tabele, $opisane);

        $brakujace = array_values(array_diff($tabele, $opisane));

        $this->assertSame(
            [],
            $brakujace,
            $this->wyjasnienieBrakow($brakujace),
        );
    }

    /**
     * KIERUNEK ODWROTNY: dokument nie opisuje tabeli, której nie ma.
     *
     * Ta połowa rozjazdu jest groźniejsza od pierwszej, bo nie wygląda na
     * brak. Sekcja opisująca nieistniejącą tabelę czyta się jak opis stanu
     * i zatrzymuje szukanie skuteczniej niż cisza — dokładnie tak, jak wiersz
     * `| Monitoring | Sentry |` w `AGENTS.md` (D-104) zatrzymał autora PR-a
     * #253 na zdaniu o powiadomieniach, których nikt nigdy nie wysyłał.
     */
    public function test_dokument_nie_opisuje_tabeli_ktorej_nie_ma(): void
    {
        $tabele = $this->tabeleWZakresie();
        $opisane = $this->tabeleOpisaneWDokumencie();

        $this->assertProgiSkanu($tabele, $opisane);

        $wszystkieWBazie = $this->wszystkieTabeleWBazie();
        $widma = array_values(array_diff($opisane, $wszystkieWBazie));

        $this->assertSame(
            [],
            $widma,
            'Te tabele mają w '.self::DOKUMENT.' własną sekcję, a w bazie ich NIE MA: '
            .implode(', ', $widma).".\n\n"
            .'Co zrobić: albo sekcja opisuje stan sprzed migracji usuwającej tabelę '
            ."i trzeba ją przepisać na czas przeszły (dokument świadomie opisuje też\n"
            .'rzeczy usunięte — ale nazywa je wprost „nie ma ich"), albo nagłówek '
            ."ma literówkę w nazwie.\n\n"
            .'Dlaczego to jest usterka, a nie drobiazg: sekcja o nieistniejącej '
            ."tabeli czyta się jak opis stanu i ZATRZYMUJE szukanie. Cisza w dokumencie\n"
            .'każe szukać dalej; nieprawda w dokumencie nie.',
        );
    }

    /**
     * KONTROLA SAMEGO WYKRYWACZA NAGŁÓWKÓW — czy umie powiedzieć „nie".
     *
     * Oba testy wyżej są zielone na dwa sposoby: gdy dokument naprawdę opisuje
     * wszystko ALBO gdy `tabeleOpisaneWDokumencie()` zwraca cokolwiek, co
     * przypadkiem pokrywa listę z bazy. Drugi przypadek jest cichy, dopóki nikt
     * nie zada wykrywaczowi pytania o rzecz, której na pewno tam nie ma.
     *
     * Kotwicą po stronie „tak" jest `cooked_event_media` — tabela, od której
     * cały ten plik się zaczął. Gdyby kiedyś zniknęła ze schematu, ta asercja
     * obleje i będzie to sygnał do podmiany kotwicy na inną tabelę, a nie do
     * usunięcia kontroli.
     */
    public function test_wykrywacz_naglowkow_umie_odpowiedziec_takze_przeczaco(): void
    {
        $opisane = $this->tabeleOpisaneWDokumencie();

        $this->assertNotContains(
            'tabela_ktorej_w_dokumencie_nie_ma',
            $opisane,
            'Wykrywacz nagłówków znalazł w '.self::DOKUMENT.' sekcję tabeli, której '
            .'tam nie ma. Dopóki odpowiada „tak" na wszystko, oba testy wyżej są '
            .'zielone niezależnie od treści dokumentu.',
        );

        $this->assertNotContains(
            'Zasady',
            $opisane,
            'Wykrywacz uznał zwykły nagłówek tekstowy („## Zasady") za sekcję tabeli. '
            .'Wtedy lista „opisanych" puchnie o nazwy, które nie są tabelami, '
            .'i test kierunku odwrotnego zacznie oblewać na sprawnym dokumencie.',
        );

        $this->assertContains(
            'cooked_event_media',
            $opisane,
            'Wykrywacz nie widzi w '.self::DOKUMENT.' sekcji „### cooked_event_media", '
            .'a ona tam jest. Wzorzec nagłówka przestał działać i test wyżej zgłosiłby '
            .'teraz wszystkie nasze tabele naraz jako nieopisane.',
        );

        $this->assertContains(
            'post_media',
            $opisane,
            'Wykrywacz nie rozłożył nagłówka zbiorczego („### posts + post_media") na '
            .'poszczególne tabele. Dokument opisuje tak cztery sekcje i bez tego '
            .'zgłosiłby jako nieopisane m.in. post_media, units i wszystkie tabele tagów.',
        );
    }

    // ---------------------------------------------------------------
    // 2. Twarde reguły schematu
    // ---------------------------------------------------------------

    /**
     * Każdy klucz obcy mówi WPROST, co się dzieje przy kasowaniu rodzica.
     *
     * Nie chodzi o to, żeby zabronić `NO ACTION` — trzy klucze w tym schemacie
     * mają jawne `ON DELETE RESTRICT` i to jest reguła, nie przeoczenie
     * (`dziennik_zgod.user_id`, `moderation_actions.moderator_id`,
     * `recipe_versions.editor_id`: konta nie kasuje się `DELETE`-em, tylko
     * anonimizuje). Chodzi o to, żeby odpowiedź na pytanie „co z tym wierszem,
     * gdy zniknie rodzic" była w schemacie ZAPISANA, a nie domyślna.
     *
     * Koszt milczenia jest niesymetryczny: brakujące `CASCADE` widać od razu
     * (baza odmawia), a brakujące `RESTRICT` widać dopiero wtedy, gdy dane już
     * zniknęły.
     */
    public function test_kazdy_klucz_obcy_ma_zdefiniowane_zachowanie_przy_kasowaniu(): void
    {
        $klucze = $this->kluczeObce();

        $this->assertGreaterThanOrEqual(
            self::MIN_KLUCZY_OBCYCH,
            count($klucze),
            'Odczyt schematu widzi tylko '.count($klucze).' kluczy obcych, a spodziewamy '
            .'się co najmniej '.self::MIN_KLUCZY_OBCYCH.'. Pusty albo obcięty odczyt nie '
            .'zgłasza usterek, bo nie ma czego zgłaszać — sprawdź, czy migracje na bazie '
            .'testowej wykonały się w całości.',
        );

        $bezZachowania = [];

        foreach ($klucze as [$tabela, $nazwa, $definicja]) {
            if (str_contains($definicja, 'ON DELETE')) {
                continue;
            }

            if (isset(self::KLUCZE_SWIADOMIE_BEZ_ON_DELETE[$nazwa])) {
                continue;
            }

            $bezZachowania[] = $tabela.'.'.$nazwa.' — '.$definicja;
        }

        $this->assertSame(
            [],
            $bezZachowania,
            "Te klucze obce nie mówią, co się dzieje przy kasowaniu rodzica:\n\n · "
            .implode("\n · ", $bezZachowania)."\n\n"
            ."Co zrobić: dopisz w migracji jawne zachowanie — `cascadeOnDelete()`,\n"
            ."`nullOnDelete()` albo `restrictOnDelete()`. Pytanie brzmi „co ma się stać\n"
            ."z tym wierszem, gdy zniknie rodzic\", a odpowiedzią WOLNO być „nic, baza ma\n"
            ."odmówić\" — ale wtedy wpisz constraint do KLUCZE_SWIADOMIE_BEZ_ON_DELETE\n"
            ."razem z powodem, tak jak `tags_merged_into_tag_id_foreign`.\n\n"
            .'Dlaczego nie wystarczy, że domyślne `NO ACTION` też jest zachowaniem: '
            ."w `\\d` tabeli wybór i przeoczenie wyglądają identycznie, a różnicę widać\n"
            .'dopiero przy kasowaniu konta na produkcji.',
        );
    }

    /**
     * Wyjątek od `ON DELETE` nie ma prawa zgnić.
     *
     * Lista wyjątków jest jedynym miejscem w tym pliku, które osłabia asercję,
     * więc pilnuje jej osobna kontrola: wpis musi wskazywać klucz, który
     * NAPRAWDĘ istnieje i NAPRAWDĘ nie ma klauzuli. Bez tego wpis przeżyłby
     * i skasowanie constraintu, i dopisanie mu `ON DELETE` — czyli wyjątek
     * zostałby otwarty na rzecz, której już nie dotyczy.
     */
    public function test_lista_wyjatkow_od_on_delete_nie_zgnila(): void
    {
        $klucze = $this->kluczeObce();

        $this->assertGreaterThanOrEqual(
            self::MIN_KLUCZY_OBCYCH,
            count($klucze),
            'Odczyt schematu widzi tylko '.count($klucze).' kluczy obcych — przy pustym '
            .'odczycie ta kontrola zgłosiłaby wyjątek jako martwy, choć jest żywy.',
        );

        $this->assertNotSame(
            [],
            self::KLUCZE_SWIADOMIE_BEZ_ON_DELETE,
            'Lista wyjątków jest pusta. Jeśli ktoś ją wyczyścił, a klucz bez `ON DELETE` '
            .'nadal jest w schemacie, to test wyżej zacznie oblewać — i to będzie '
            .'właściwe zachowanie. Ten komunikat mówi tylko, że ta kontrola straciła '
            .'przedmiot.',
        );

        $martwe = [];

        foreach (self::KLUCZE_SWIADOMIE_BEZ_ON_DELETE as $nazwa => $powod) {
            $pasujace = array_values(array_filter(
                $klucze,
                static fn (array $k): bool => $k[1] === $nazwa,
            ));

            if ($pasujace === []) {
                $martwe[] = $nazwa.' — nie ma takiego klucza obcego w schemacie ('.$powod.')';

                continue;
            }

            if (str_contains($pasujace[0][2], 'ON DELETE')) {
                $martwe[] = $nazwa.' — ma już jawne `ON DELETE`, wyjątek jest zbędny ('.$powod.')';
            }
        }

        $this->assertSame(
            [],
            $martwe,
            "Te wpisy w KLUCZE_SWIADOMIE_BEZ_ON_DELETE straciły przedmiot:\n\n · "
            .implode("\n · ", $martwe)."\n\n"
            .'Co zrobić: wykreśl je z listy. Martwy wyjątek jest gorszy od żadnego — '
            ."zostaje otwarty na rzecz, której już nie dotyczy, i pierwszy nowy klucz\n"
            .'o zbieżnej nazwie przeszedłby przez niego bez pytania.',
        );
    }

    /**
     * E-mail i nazwa użytkownika: unikalność BEZ WZGLĘDU NA WIELKOŚĆ LITER.
     *
     * Zwykły `UNIQUE (email)` tego nie daje — PostgreSQL porównuje teksty co do
     * znaku, więc `Jan@example.com` i `jan@example.com` to dla niego dwa różne
     * adresy. Dla człowieka to jeden adres, a dla klawiatury telefonu, która
     * kapitalizuje pierwszą literę, to zachowanie domyślne, nie wyjątek.
     *
     * Reguła musi stać w BAZIE, nie w mutatorze `User::email` ani w regule
     * walidacji: `AGENTS.md` §6 („walidacja w PHP jest dodatkiem, nie
     * zamiennikiem") i D-079 („gwarancję daje constraint albo blokada, nie
     * `exists()` w PHP"). Mutator zostaje — na ładny komunikat.
     *
     * Test nie szuka indeksu po NAZWIE, tylko po KSZTAŁCIE (unikalny, na tej
     * tabeli, z `lower(` i nazwą kolumny w definicji): przemianowanie indeksu
     * nie jest rozjazdem i nie ma prawa oblewać. Oczekiwana nazwa jest za to
     * w komunikacie, żeby naprawa nie wymagała szukania.
     */
    public function test_email_i_nazwa_uzytkownika_sa_unikalne_bez_wzgledu_na_wielkosc_liter(): void
    {
        $indeksy = $this->indeksyUnikalne();

        $this->assertGreaterThanOrEqual(
            self::MIN_INDEKSOW_UNIKALNYCH,
            count($indeksy),
            'Odczyt schematu widzi tylko '.count($indeksy).' indeksów unikalnych, '
            .'a spodziewamy się co najmniej '.self::MIN_INDEKSOW_UNIKALNYCH.'. '
            .'Przy pustym odczycie ten test zgłosiłby brak obu indeksów, choć są — '
            .'sprawdź zapytanie do pg_indexes i to, czy migracje wykonały się w całości.',
        );

        foreach (self::UNIKALNE_BEZ_WIELKOSCI_LITER as [$tabela, $kolumna, $oczekiwanaNazwa]) {
            $this->assertTrue(
                $this->kolumnaIstnieje($tabela, $kolumna),
                'W schemacie nie ma kolumny `'.$tabela.'.'.$kolumna.'`, a to o nią pyta '
                .'ten test. Asercja niżej byłaby wtedy pytaniem o indeks na czymś, czego '
                .'nie ma — czyli oblewałaby z niewłaściwego powodu.',
            );

            $this->assertTrue(
                $this->maIndeksBezWielkosciLiter($indeksy, $tabela, $kolumna),
                'Kolumna `'.$tabela.'.'.$kolumna.'` nie ma unikalnego indeksu '
                ."niewrażliwego na wielkość liter.\n\n"
                .'Co zrobić: migracją dołóż '
                .'`CREATE UNIQUE INDEX '.$oczekiwanaNazwa.' ON '.$tabela
                .' (lower('.$kolumna."));`\n\n"
                .'Dlaczego nie wystarczy zwykły `UNIQUE` ani mutator w modelu: '
                ."PostgreSQL porównuje teksty co do znaku, więc konto założone jako\n"
                .'`Jan@example.com` było nie do zalogowania przez `jan@example.com`, '
                ."a nazwa profilu `Basia` i `basia` dałyby dwa różne profile pod jednym\n"
                .'adresem czytanym przez człowieka. Mutator jest dodatkiem, nie '
                .'zamiennikiem (AGENTS.md §6, D-079).',
            );
        }
    }

    /**
     * KONTROLA WYKRYWACZA INDEKSÓW — czy on w ogóle umie powiedzieć „nie ma".
     *
     * Test wyżej składa się z samych asercji dodatnich, więc byłby zielony
     * także wtedy, gdyby `maIndeksBezWielkosciLiter()` odpowiadało „tak" na
     * dowolne pytanie (`docs/PULAPKI_TESTOW.md`, pułapka 4: para „widoczna
     * wyszła, ukryta nie wyszła" dopiero dowodzi, że mechanizm pracował).
     *
     * Kotwicą po stronie „nie" jest `users.password` — kolumna, która istnieje,
     * żadnego indeksu unikalnego nie ma i mieć nie może.
     */
    public function test_wykrywacz_indeksow_umie_odpowiedziec_takze_przeczaco(): void
    {
        $indeksy = $this->indeksyUnikalne();

        $this->assertTrue(
            $this->kolumnaIstnieje('users', 'password'),
            'Nie ma kolumny `users.password`, a to na niej stoi kontrola ujemna tego '
            .'wykrywacza. Podmień kotwicę na inną kolumnę bez indeksu unikalnego.',
        );

        $this->assertFalse(
            $this->maIndeksBezWielkosciLiter($indeksy, 'users', 'password'),
            'Wykrywacz twierdzi, że `users.password` ma unikalny indeks funkcyjny na '
            .'`lower(...)`. Albo odpowiada „tak" na wszystko — i wtedy test wyżej nie '
            .'pilnuje niczego — albo ktoś naprawdę założył taki indeks na haśle, co '
            .'byłoby usterką bezpieczeństwa samą w sobie.',
        );

        $this->assertFalse(
            $this->maIndeksBezWielkosciLiter($indeksy, 'users', 'username'),
            'Wykrywacz znalazł indeks `lower(username)` na tabeli `users`. Nazwa '
            .'użytkownika leży w `profiles`, nie w `users` — czyli wykrywacz nie patrzy '
            .'na TABELĘ, tylko na samą nazwę kolumny, i zaliczyłby cudzy indeks.',
        );
    }

    // ---------------------------------------------------------------
    // Schemat
    // ---------------------------------------------------------------

    /** @return list<string> */
    private function wszystkieTabeleWBazie(): array
    {
        $wiersze = DB::select(
            "SELECT table_name
               FROM information_schema.tables
              WHERE table_schema = current_schema()
                AND table_type = 'BASE TABLE'
              ORDER BY table_name",
        );

        return array_map(static fn (object $w): string => (string) $w->table_name, $wiersze);
    }

    /**
     * Nasze tabele — wszystko poza ośmioma tabelami frameworka.
     *
     * @return list<string>
     */
    private function tabeleWZakresie(): array
    {
        return array_values(array_diff($this->wszystkieTabeleWBazie(), self::TABELE_FRAMEWORKA));
    }

    /**
     * Klucze obce schematu: tabela, nazwa constraintu, pełna definicja.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function kluczeObce(): array
    {
        $wiersze = DB::select(
            "SELECT c.conrelid::regclass::text AS tabela,
                    c.conname                  AS nazwa,
                    pg_get_constraintdef(c.oid) AS definicja
               FROM pg_constraint c
               JOIN pg_namespace n ON n.oid = c.connamespace
              WHERE c.contype = 'f'
                AND n.nspname = current_schema()
              ORDER BY 1, 2",
        );

        return array_map(
            static fn (object $w): array => [
                (string) $w->tabela,
                (string) $w->nazwa,
                (string) $w->definicja,
            ],
            $wiersze,
        );
    }

    /**
     * Indeksy UNIKALNE schematu: tabela, nazwa, definicja.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function indeksyUnikalne(): array
    {
        $wiersze = DB::select(
            "SELECT tablename AS tabela, indexname AS nazwa, indexdef AS definicja
               FROM pg_indexes
              WHERE schemaname = current_schema()
                AND indexdef LIKE 'CREATE UNIQUE INDEX%'
              ORDER BY 1, 2",
        );

        return array_map(
            static fn (object $w): array => [
                (string) $w->tabela,
                (string) $w->nazwa,
                (string) $w->definicja,
            ],
            $wiersze,
        );
    }

    private function kolumnaIstnieje(string $tabela, string $kolumna): bool
    {
        $wiersze = DB::select(
            'SELECT 1
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND table_name = ?
                AND column_name = ?',
            [$tabela, $kolumna],
        );

        return $wiersze !== [];
    }

    /**
     * Czy ta KOLUMNA tej TABELI ma unikalny indeks po `lower(...)`.
     *
     * Szukamy po kształcie, nie po nazwie: indeks ma być unikalny, stać na tej
     * tabeli i mieć w definicji zarówno `lower(` jak i nazwę kolumny. Dzięki
     * temu przemianowanie indeksu nie oblewa testu, a założenie go na innej
     * tabeli — nie zalicza się (patrz kontrola ujemna wyżej).
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $indeksy
     */
    private function maIndeksBezWielkosciLiter(array $indeksy, string $tabela, string $kolumna): bool
    {
        foreach ($indeksy as [$tabelaIndeksu, , $definicja]) {
            if ($tabelaIndeksu !== $tabela) {
                continue;
            }

            if (! str_contains($definicja, 'lower(')) {
                continue;
            }

            if (preg_match('/lower\(\(?'.preg_quote($kolumna, '/').'\b/', $definicja) === 1) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------
    // Dokument
    // ---------------------------------------------------------------

    /**
     * Tabele, które mają w dokumencie WŁASNY nagłówek sekcji.
     *
     * Kształt nagłówka tabeli w tym dokumencie jest jeden i ustalony:
     * `### nazwa`, `## \`nazwa\``, `### a + b` (sekcja zbiorcza dla tabel,
     * których nie da się opisać osobno — `posts + post_media`), ewentualnie
     * z dopiskiem po myślniku: `### collection_items — przepisy ORAZ wpisy`.
     *
     * Za nagłówek TABELI uznajemy taki, w którym po odcięciu dopisku i grawisów
     * **każdy** człon rozdzielony plusem jest identyfikatorem SQL-a pisanym
     * małymi literami. To odróżnia `### recipe_steps` od `## Zasady`,
     * `## Wyszukiwarka: funkcja kuking_normalize()` i `### Wspomnienia „Rok
     * temu gotowałaś…"` bez listy wyjątków, która musiałaby rosnąć przy każdym
     * nowym nagłówku tekstowym.
     *
     * Poziom `####` jest świadomie poza zakresem: to są podsekcje kolumn
     * (`#### \`wants_weekly_digest\``), nie tabel.
     *
     * @return list<string>
     */
    private function tabeleOpisaneWDokumencie(): array
    {
        $nazwy = [];

        foreach (explode("\n", $this->dokument()) as $linia) {
            if (preg_match('/^(#{2,3})\s+(.+?)\s*$/u', $linia, $trafienie) !== 1) {
                continue;
            }

            $nazwa = explode(' — ', $trafienie[2])[0];
            $nazwa = str_replace('`', '', trim($nazwa));

            $czlony = array_map('trim', explode('+', $nazwa));
            $identyfikatory = array_filter(
                $czlony,
                static fn (string $c): bool => preg_match('/^[a-z][a-z0-9_]*$/', $c) === 1,
            );

            if (count($identyfikatory) !== count($czlony)) {
                continue;
            }

            foreach ($czlony as $czlon) {
                $nazwy[$czlon] = true;
            }
        }

        return array_keys($nazwy);
    }

    private function dokument(): string
    {
        $sciezka = base_path(self::DOKUMENT);

        $this->assertFileExists(
            $sciezka,
            'Nie ma '.self::DOKUMENT.' — a to jedyny dokument, wobec którego ten test '
            .'sprawdza schemat bazy.',
        );

        return (string) file_get_contents($sciezka);
    }

    // ---------------------------------------------------------------
    // Progi i komunikaty
    // ---------------------------------------------------------------

    /**
     * @param  list<string>  $tabele
     * @param  list<string>  $opisane
     */
    private function assertProgiSkanu(array $tabele, array $opisane): void
    {
        $this->assertGreaterThanOrEqual(
            self::MIN_DLUGOSC_DOKUMENTU,
            mb_strlen($this->dokument()),
            'W '.self::DOKUMENT.' widać tylko '.mb_strlen($this->dokument()).' znaków, '
            .'a spodziewamy się co najmniej '.self::MIN_DLUGOSC_DOKUMENTU.'. Ten dokument '
            .'nie chudnie o połowę, więc to usterka ODCZYTU, nie treści.',
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_TABEL_W_BAZIE,
            count($this->wszystkieTabeleWBazie()),
            'Odczyt schematu widzi tylko '.count($this->wszystkieTabeleWBazie()).' tabel, '
            .'a spodziewamy się co najmniej '.self::MIN_TABEL_W_BAZIE.'. Skan pustej bazy '
            .'nie zgłasza rozjazdów, bo nie ma czego porównywać — sprawdź, czy migracje '
            .'na bazie testowej wykonały się w całości.',
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_TABEL_W_ZAKRESIE,
            count($tabele),
            'Po odjęciu tabel frameworka zostało tylko '.count($tabele).' tabel, '
            .'a spodziewamy się co najmniej '.self::MIN_TABEL_W_ZAKRESIE.'. Jeżeli liczba '
            .'spadła, bo nasze tabele trafiły do TABELE_FRAMEWORKA — to nie jest '
            .'sprzątanie, tylko wyłączanie tego testu.',
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_NAGLOWKOW_TABEL,
            count($opisane),
            'W '.self::DOKUMENT.' widać tylko '.count($opisane).' nagłówków tabel, '
            .'a spodziewamy się co najmniej '.self::MIN_NAGLOWKOW_TABEL.'. Tyle naraz '
            .'nie ubywa z dokumentu — to usterka WYKRYWACZA nagłówków (zmieniony styl '
            .'nagłówków? inny poziom `#`?), a nie dokumentu. Bez tego progu zgłosiłby '
            .'teraz wszystkie nasze tabele jako nieopisane.',
        );
    }

    /**
     * Komunikat wymienia TABELĘ Z NAZWY i mówi, co z nią zrobić.
     *
     * Usterkę naprawia człowiek dopisujący sekcję do `docs/DATABASE.md`, nie
     * ten, kto uruchomił testy. Bez nazwy tabeli pierwszym krokiem po czerwonym
     * wyniku byłoby to samo zapytanie do `information_schema`, które ten test
     * właśnie wykonał (`docs/PULAPKI_TESTOW.md`, pułapka 8 pkt 3: czerwień bez
     * przeczytanej przyczyny nie jest informacją).
     *
     * @param  list<string>  $brakujace
     */
    private function wyjasnienieBrakow(array $brakujace): string
    {
        if ($brakujace === []) {
            return '';
        }

        $linie = [
            'Te tabele są w bazie, a '.self::DOKUMENT.' nie ma dla nich ANI JEDNEJ sekcji:',
            '',
        ];

        foreach ($brakujace as $tabela) {
            $linie[] = ' · '.$tabela;
        }

        $linie[] = '';
        $linie[] = 'Co zrobić: dopisz sekcję `### '.$brakujace[0].'` w '.self::DOKUMENT.',';
        $linie[] = 'w stylu sąsiednich sekcji. Nie „lista kolumn plus typy" — to widać';
        $linie[] = 'w migracji. Napisz, CO w tej tabeli leży, czym jest NULL, co się';
        $linie[] = 'dzieje przy kasowaniu rodzica i jak wygląda rollback.';
        $linie[] = '';
        $linie[] = 'Tabela opisana razem z inną wchodzi do nagłówka zbiorczego';
        $linie[] = '(`### posts + post_media`) — wykrywacz rozkłada go po plusach.';
        $linie[] = '';
        $linie[] = 'Powód, dla którego pilnuje tego test: 12.09.2026 w schemacie stała';
        $linie[] = 'tabela `cooked_event_media` — trzy kolumny, dwie kaskady, obie listy';
        $linie[] = 'odwołań do `media` — o której dokument nie mówił ANI SŁOWA. Wyglądał';
        $linie[] = 'przy tym na kompletny, bo 41 innych tabel w nim było. Dokument, który';
        $linie[] = 'rozjechał się z bazą, jest gorszy niż brak dokumentu: wygląda na';
        $linie[] = 'sprawdzony i zatrzymuje szukanie.';
        $linie[] = '';
        $linie[] = 'Czego NIE robić: dopisywać tabeli do TABELE_FRAMEWORKA. Ta lista jest';
        $linie[] = 'dla ośmiu tabel Laravela i ma taka zostać — obowiązek opisu stawia';
        $linie[] = 'AGENTS.md („zmiana schematu = migracja + test + docs/DATABASE.md +';
        $linie[] = 'rollback"), a ten test go tylko przypomina.';

        return implode("\n", $linie);
    }
}
