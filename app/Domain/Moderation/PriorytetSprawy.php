<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Report;

/**
 * PRIORYTET SPRAWY MODERACYJNEJ — JEDNO MIEJSCE, W KTÓRYM ŻYJE PODZIAŁ P0–P3
 * (D-070).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TA KLASA ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `docs/legal/MODERATION_PLAYBOOK.md` §3 ma tabelę SLA z czterema
 * priorytetami i przypisanymi do nich kategoriami naruszeń. Do 10 września
 * 2026 ta tabela była JEDYNYM miejscem, w którym ten podział istniał —
 * podręcznik przyznawał to wprost: „Podział P0–P3 wyżej to porządek w głowie
 * moderatora i nic go nie wymusza". `reports` nie miało kolumny priorytetu,
 * a `/admin/zgloszenia` sortowało od najnowszych, więc sprawa P0 sprzed dwóch
 * dni leżała niżej niż spam sprzed godziny.
 *
 * Przy jednym–dwóch moderatorach to nie jest niedogodność. Człowiek bez
 * redundancji ma gorsze dni, urlopy i grypę; wtedy kolejność musi pilnować
 * system, bo nie ma nikogo, kto by go poprawił.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO MAPOWANIE JEST TUTAJ, A NIE W MIGRACJI, MODELU I WIDOKU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo podręcznik i kod nie mogą się rozjechać. Gdyby `CASE` z priorytetami
 * stał w SQL-u migracji, drugi w `Report`, a trzeci w Blade, pierwsza zmiana
 * polityki („oszustwo idzie wyżej") poprawiłaby jedno z trzech miejsc i nikt
 * by tego nie zauważył — bo rozjazd priorytetów nie wywala testu, tylko
 * przesuwa sprawę o kilka pozycji w kolejce.
 *
 * Wszystko, co zna ten podział, pyta więc TĘ klasę:
 *  - `Report::booted()` przy tworzeniu wiersza (`dlaPowodu()`),
 *  - migracja `2026_09_10_400000_priorytet_w_kolejce_zgloszen` przy
 *    wypełnianiu kolumny w starych wierszach (buduje `CASE` z `MAPOWANIE`,
 *    zamiast wpisywać liczby po swojemu),
 *  - kolejka i panel przy wyświetlaniu (`nazwa()`, `etykieta()`, `cel()`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO LICZBA 0–3, A NIE TEKST 'P0'–'P3'
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo kolejka sortuje po tej kolumnie i ma to robić samym indeksem:
 * `ORDER BY priorytet ASC, created_at ASC` z indeksem częściowym
 * `reports_kolejka_priorytet_idx` czyta wiersze już w kolejności. Tekst
 * wymagałby `ORDER BY CASE priorytet WHEN 'P0' THEN 0 …`, czyli wyrażenia,
 * którego indeks nie obsłuży — a to jest dokładnie ta sytuacja, w której
 * `Report::WAGA` (kolejność kolejki automatu) świadomie ZOSTAJE w PHP:
 * tamto jest regułą produktu czytaną dla garści wierszy, to jest porządkiem
 * całej tabeli.
 *
 * Mniejsza liczba znaczy PILNIEJSZE, żeby zwykłe `ASC` (i domyślna kolejność
 * indeksu) dawały właściwy porządek bez ani jednego `DESC`. Człowiek widzi
 * „P0", nie „0" — zamienia jedno na drugie `nazwa()` i nikt inny.
 */
final class PriorytetSprawy
{
    /** Krytyczny: CSAM, groźby zagrażające życiu, aktywny doxxing. */
    public const P0 = 0;

    /** Pilny: nękanie, mowa nienawiści, dane osobowe osób trzecich, nagość. */
    public const P1 = 1;

    /** Standardowy: spam, prawa autorskie, niebezpieczne porady, podszywanie. */
    public const P2 = 2;

    /** Niski: drobne naruszenia, kategorie wątpliwe. */
    public const P3 = 3;

    /**
     * Priorytet, którego dostaje sprawa bez rozpoznanego powodu.
     *
     * P2, nie P3 i nie P0. Powód spoza listy znaczy „nie wiemy, co to jest" —
     * a nie wiedzieć nie może znaczyć ani „na pewno drobne" (sprawa
     * przepadłaby na tygodniowym przeglądzie), ani „na pewno krytyczne"
     * (nieznany kod przy zmianie formularza podniósłby cały ruch na sam
     * szczyt i alarm P0 przestałby cokolwiek znaczyć). Standard to jedyna
     * odpowiedź, która przy pomyłce w obie strony kosztuje 72 godziny.
     */
    public const DOMYSLNY = self::P2;

    /**
     * POWÓD ZGŁOSZENIA → PRIORYTET. To jest odwzorowanie tabeli SLA
     * z `docs/legal/MODERATION_PLAYBOOK.md` §3 i nic więcej.
     *
     * Klucze to `Report::REASONS` (wybór ZGŁASZAJĄCEGO) oraz
     * `Report::REASONS_AUTOMAT` (kod postawiony przez wykrywacz). Każdy
     * powód, jaki produkt umie zapisać, musi tu być — pilnuje tego
     * `PriorytetKolejkiZgloszenTest::test_kazdy_powod_zgloszenia_ma_przypisany_priorytet`,
     * żeby dopisanie kategorii do formularza nie zostawiło jej po cichu
     * na wartości domyślnej.
     *
     * DECYZJE, KTÓRE NIE WYNIKAJĄ Z TABELI WPROST — wypisane, bo bez
     * uzasadnienia wyglądają na pomyłkę:
     *
     *  - `minor` („Dotyczy dziecka") to P0, choć etykieta jest szersza niż
     *    „CSAM" z podręcznika. Automat czyta wyłącznie wybraną kategorię,
     *    nie treść, więc nie wie, czy chodzi o seksualizację dziecka, czy
     *    o czternastolatka z własnym kontem. Koszt pomyłki jest tu
     *    skrajnie niesymetryczny: fałszywy P0 zabiera moderatorowi kilka
     *    minut i jedno obniżenie priorytetu, przegapione CSAM jest
     *    najgorszą rzeczą, jaka może się w tym serwisie stać. Przy takiej
     *    asymetrii domyślną odpowiedzią jest szczyt kolejki.
     *
     *  - `personal_data` („Ujawnia czyjeś dane osobowe") to P1, a nie P0,
     *    choć podręcznik wpisuje „aktywny doxxing" do P0. Różnica między
     *    numerem telefonu wklejonym bezmyślnie i adresem opublikowanym po
     *    to, żeby ktoś tam pojechał, siedzi w TREŚCI i w intencji — czyli
     *    dokładnie tam, gdzie automat nie sięga. Domyślnie P1 (24 godziny),
     *    a moderator, który zobaczy doxxing, podnosi do P0 ręcznie
     *    (`Report::zmienPriorytet()`). To jest główny powód, dla którego
     *    ręczna zmiana priorytetu w ogóle istnieje.
     *
     *  - `scam` („Oszustwo lub podejrzany link") to P1, a nie P2 razem ze
     *    spamem. Podręcznik ma w P2 „spam / reklama ukryta" — reklamę, czyli
     *    zmarnowaną minutę czytelnika. Podstawiony link to wyłudzenie
     *    pieniędzy albo danych karty u grupy 50+, która jest głównym celem
     *    takich kampanii. Ten sam rachunek stoi już w `Report::WAGA`, gdzie
     *    wzorzec spamu jest wysoko właśnie „przy trafieniu szkoda jest
     *    największa (oszustwo, wyłudzenie)".
     *
     *  - `other` („Coś innego") to P3, zgodnie z wierszem „wątpliwe
     *    kategorie". Nie znaczy „nieważne": znaczy, że nikt nie umiał tego
     *    nazwać, więc sprawa czeka na przegląd, a nie wypycha z kolejki
     *    czegoś, co nazwę ma.
     *
     *  - powody automatu (`automat_*`) mają priorytety, choć kolejka
     *    automatu (`/admin/sygnaly`) porządkuje się osobno, po
     *    `Report::WAGA`. Kolumna musi mieć wartość w KAŻDYM wierszu, a te
     *    sprawy trafiają też na `/admin/zgloszenia?zrodlo=automat`, gdzie
     *    obowiązuje ten sam porządek co wszędzie. Kierunek jest zgodny
     *    z `WAGA` (ocena modelem najwyżej, powtórzenie najniżej) — sprawdza
     *    to osobny test, żeby dwa porządki nie zaczęły mówić czego innego.
     *    ŻADEN powód automatu nie daje P0: automat nie podnosi ręki
     *    w sprawie życia i zdrowia, a alarm P0 ma zostać sygnałem od
     *    człowieka. Treści pilne z modelu mają własny, starszy kanał
     *    (`AlarmujModeratora`, D-055) i on nie zmienia się tą decyzją.
     *
     * @var array<string, int>
     */
    public const MAPOWANIE = [
        // P0 — krytyczny
        'minor' => self::P0,

        // P1 — pilny
        'harassment' => self::P1,
        'hate' => self::P1,
        'sexual' => self::P1,
        'personal_data' => self::P1,
        'scam' => self::P1,

        // P2 — standardowy
        'spam' => self::P2,
        'impersonation' => self::P2,
        'copyright' => self::P2,
        'dangerous_advice' => self::P2,

        // P3 — niski
        'other' => self::P3,

        // Powody automatu (D-052). Kierunek zgodny z `Report::WAGA`.
        'automat_model' => self::P1,
        'automat_wzorzec' => self::P2,
        'automat_odnosnik' => self::P2,
        'automat_powtorzenie' => self::P3,
    ];

    /**
     * Nazwa, którą widzi moderator. Krótka, bo stoi na plakietce.
     *
     * @var array<int, string>
     */
    private const NAZWY = [
        self::P0 => 'P0',
        self::P1 => 'P1',
        self::P2 => 'P2',
        self::P3 => 'P3',
    ];

    /**
     * Pełna etykieta — plakietka sama nie mówi, co znaczy „P2".
     *
     * `AGENTS.md` §5: skrót nigdy nie jest jedynym opisem. „P2" bez słowa
     * „standardowy" jest dla nowego moderatora dokładnie tym samym, czym
     * ikona bez podpisu.
     *
     * @var array<int, string>
     */
    private const ETYKIETY = [
        self::P0 => 'P0 — krytyczny',
        self::P1 => 'P1 — pilny',
        self::P2 => 'P2 — standardowy',
        self::P3 => 'P3 — niski',
    ];

    /**
     * Cel czasowy z tabeli SLA podręcznika — zdaniem, nie liczbą godzin.
     *
     * Nie liczymy z tego terminu i nie pokazujemy zegara. To jest cel
     * operacyjny zespołu, nie termin ustawowy (te mają swoje miejsca:
     * `Appeal::responseDeadline()`, `Report::receipt_sent_at`), a zegar przy
     * sprawie sugerowałby obietnicę, której przy jednym moderatorze nie da
     * się złożyć — podręcznik mówi o tym wprost w akapicie „Zasada
     * realistyczna".
     *
     * @var array<int, string>
     */
    private const CELE = [
        self::P0 => 'natychmiast, poza kolejnością wszystkiego innego',
        self::P1 => 'w ciągu 24 godzin w dni robocze',
        self::P2 => 'w ciągu 72 godzin',
        self::P3 => 'w ciągu 7 dni',
    ];

    /**
     * Priorytet wynikający z POWODU zgłoszenia.
     *
     * Powód spoza mapowania nie jest błędem wywołania: w bazie leżą sprawy
     * sprzed tej zmiany, a formularz zgłoszenia może dostać nową kategorię
     * wcześniej niż ta tablica. Wtedy oddajemy `DOMYSLNY` — patrz komentarz
     * przy tej stałej.
     */
    public static function dlaPowodu(?string $powod): int
    {
        if ($powod === null) {
            return self::DOMYSLNY;
        }

        return self::MAPOWANIE[$powod] ?? self::DOMYSLNY;
    }

    /** Czy ta liczba jest jednym z czterech znanych priorytetów. */
    public static function poprawny(int $priorytet): bool
    {
        return array_key_exists($priorytet, self::NAZWY);
    }

    /** @return list<int> od najpilniejszego */
    public static function wszystkie(): array
    {
        return array_keys(self::NAZWY);
    }

    public static function nazwa(int $priorytet): string
    {
        return self::NAZWY[$priorytet] ?? 'P?';
    }

    public static function etykieta(int $priorytet): string
    {
        return self::ETYKIETY[$priorytet] ?? 'priorytet nieznany';
    }

    public static function cel(int $priorytet): string
    {
        return self::CELE[$priorytet] ?? 'bez celu czasowego';
    }

    /**
     * Lista do pola wyboru przy ręcznej zmianie priorytetu.
     *
     * @return array<int, string>
     */
    public static function dlaFormularza(): array
    {
        return self::ETYKIETY;
    }

    /**
     * Fragment SQL `CASE … END` zamieniający `reason` na priorytet.
     *
     * ISTNIEJE WYŁĄCZNIE DLA MIGRACJI wypełniającej kolumnę w wierszach,
     * które powstały przed tą zmianą. Kolejka NIE używa tego wyrażenia —
     * czyta gotową kolumnę, bo po to ona jest.
     *
     * Zwracamy SQL, a nie tablicę, żeby migracja nie musiała sama układać
     * `CASE` (czyli powtarzać wiedzy o kształcie mapowania). Wartości są
     * `int`-ami z tej klasy i klucze przechodzą przez `addslashes()` — nic
     * tu nie przychodzi z zewnątrz, ale wyrażenie i tak trafia do
     * `DB::statement()`, więc nie budujemy nawyku, którego nie chcemy
     * zobaczyć w miejscu, gdzie dane BĘDĄ z zewnątrz.
     */
    public static function sqlZPowodu(string $kolumna = 'reason'): string
    {
        $galezie = '';

        foreach (self::MAPOWANIE as $powod => $priorytet) {
            $galezie .= " WHEN '".addslashes($powod)."' THEN ".$priorytet;
        }

        return 'CASE '.$kolumna.$galezie.' ELSE '.self::DOMYSLNY.' END';
    }

    /**
     * Powody, które dają dany priorytet — do dokumentacji i do testów.
     *
     * @return list<string>
     */
    public static function powodyDla(int $priorytet): array
    {
        return array_keys(array_filter(
            self::MAPOWANIE,
            static fn (int $wartosc): bool => $wartosc === $priorytet,
        ));
    }

    /**
     * Czy ten powód pochodzi od automatu.
     *
     * Osobno od `Report::wykrylAutomat()`, bo tamto pyta o WIERSZ (kolumnę
     * `source`), a to o SAM KOD powodu — potrzebne w teście spójności
     * mapowania z `Report::WAGA`, gdzie żadnego wiersza nie ma.
     */
    public static function powodAutomatu(string $powod): bool
    {
        return array_key_exists($powod, Report::REASONS_AUTOMAT);
    }
}
