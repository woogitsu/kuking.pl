<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wersja aplikacji pokazywana w stopce.
 *
 * SKŁADA SIĘ Z TRZECH CZĘŚCI, BO KAŻDA ODPOWIADA NA INNE PYTANIE
 *
 *   „Alfa 0.1"        — na jakim etapie jest produkt. Zmienia się rzadko,
 *                       ręcznie, razem z kamieniami z docs/ROADMAP.md.
 *
 *   „8 września 2026, — KIEDY to wydanie powstało. To jest pytanie, które
 *    12:40"             pada najczęściej: „czy to, na co patrzę, jest już po
 *                       mojej ostatniej poprawce". Data odpowiada od razu.
 *
 *   „a1b2c3d"         — CO DOKŁADNIE jest wdrożone. Zmienia się przy każdym
 *                       wdrożeniu, samo, bez niczyjej pamięci.
 *
 * Sama etykieta nie wystarcza: stałaby tygodniami bez zmian. Sam skrót
 * commita też nie: siedmiu znaków szesnastkowych nie porówna z pamięcią
 * nikt, kto nie ma obok historii gita — a właśnie po to się na wersję patrzy.
 * Data bez skrótu byłaby z kolei bezużyteczna przy zgłoszonej usterce: mówi,
 * KIEDY, ale nie CO.
 *
 * Skrót bierzemy z RAILWAY_GIT_COMMIT_SHA — Railway wstrzykuje ją do każdego
 * wdrożenia. To jest cała wartość tego rozwiązania: gdy ktoś zgłasza usterkę
 * i przepisze to, co widzi w stopce, wiadomo, którego commita zgłoszenie
 * dotyczy — bez odtwarzania z pamięci, kiedy to dokładnie było.
 *
 * CZEGO TU NIE MA, CHOĆ STAŁO NAPISANE DO 10 WRZEŚNIA 2026
 * Ten komentarz twierdził, że ta sama wartość idzie do SENTRY_RELEASE, „więc
 * wersja w stopce i wersja przy błędzie w Sentry to dokładnie ten sam commit".
 * SENTRY_RELEASE było wtedy ustawiane w `.railway/railway.ts` (usunięte w #1013), ale
 * **Sentry'ego w tym projekcie nie ma**: nie ma pakietu w `composer.json`, nie
 * ma `config/sentry.php`, a `SENTRY_LARAVEL_DSN` nie czyta ani jedna linijka
 * PHP (D-041). Wpisów przy błędzie, do których ten skrót miałby pasować, nie
 * ma więc żadnych. Błędy 500 idą dziś na kanał `blad_webhook`, a ten wysyła
 * klasę wyjątku, plik:linię i wzorzec trasy — bez numeru wydania. Powiązanie
 * zgłoszenia z commitem robi się dziś ręcznie, przez stopkę, i to jest jedyny
 * powód, dla którego ten skrót w ogóle w niej stoi.
 *
 * KOŃCÓWKA WDROŻENIA — „.005" PO ETYKIECIE (issue #1932, D-318)
 * Etykieta sama w sobie stoi tygodniami. Między dwoma jej podbiciami ląduje
 * na produkcji po kilkanaście wdrożeń dziennie, a stopka nie miała jak ich
 * rozróżnić — dwa różne wdrożenia tego samego dnia wyglądały identycznie,
 * dopóki ktoś nie porównał skrótów commitów z pamięci. `etykietaZNumerem()`
 * dokłada więc numer kolejny w dzienniku `wdrozenia`, liczony PRZEZ
 * `kuking:zarejestruj-wdrozenie` w kroku wdrożenia (`.railway/railway.ts`),
 * nie przez tę klasę — `Wersja` tylko CZYTA już zapisaną wartość, z cache'em.
 */
final class Wersja
{
    /** Ile znaków skrótu pokazujemy. Siedem to konwencja gita — wystarcza. */
    private const DLUGOSC_SKROTU = 7;

    /**
     * Pełny opis do stopki, np.
     * „Alfa 0.1 · 8 września 2026, 12:40 · a1b2c3d".
     *
     * Lokalnie i w testach nie ma ani znacznika builda, ani zmiennej od
     * Railway — wtedy zostaje samo „Alfa 0.1 · lokalnie", bo zamiast pustego
     * miejsca albo myślnika mówimy wprost, że to nie jest wdrożona wersja.
     */
    public static function pelna(): string
    {
        return self::etykietaZNumerem().' · '.self::opisWydania();
    }

    /**
     * To, co stoi w stopce PO etapie produktu: data wydania i skrót commita,
     * albo tyle z tego, ile w ogóle wiadomo.
     *
     * DLACZEGO DATA IDZIE PRZED SKRÓTEM, a nie odwrotnie: data jest tą
     * częścią, którą człowiek czyta. Skrót zostaje, bo to on wiąże ekran,
     * na który ktoś patrzy, z konkretnym commitem — bez niego przy zgłoszonym
     * błędzie nie da się powiedzieć, którego kodu dotyczy.
     */
    public static function opisWydania(): string
    {
        $data = self::dataWydania();

        if ($data === null) {
            return self::wydanie();
        }

        return 'wydanie '.Czas::data($data, 'j F Y, H:i').' · '.self::wydanie();
    }

    /**
     * Kiedy powstało to wydanie — albo `null`, gdy nie wiadomo.
     *
     * ŹRÓDŁO: znacznik zapisany przez build obrazu (Dockerfile) do pliku
     * `bootstrap/wydanie.txt`, w UTC, w formacie ISO-8601. Railway nie
     * wstrzykuje czasu wdrożenia żadną zmienną, więc nie ma czego czytać
     * ze środowiska — poza jawnym nadpisaniem `KUKING_WYDANO`, które wygrywa
     * z plikiem (przydaje się, gdy trzeba coś skorygować bez przebudowy).
     *
     * NIEPARSOWALNY ZNACZNIK TO `null`, NIE WYJĄTEK. Ten tekst idzie do
     * stopki KAŻDEJ strony w serwisie. Uszkodzony plik ma zabrać datę,
     * a nie wywalić całą aplikację — łącznie ze stroną logowania, z której
     * ktoś musiałby wejść, żeby to naprawić.
     */
    public static function dataWydania(): ?CarbonImmutable
    {
        $znacznik = config('kuking.wersja.wydano');

        if (! is_string($znacznik) || trim($znacznik) === '') {
            $znacznik = self::znacznikZPliku();
        }

        if ($znacznik === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($znacznik), 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Zawartość pliku ze znacznikiem builda albo pusty napis. */
    private static function znacznikZPliku(): string
    {
        $sciezka = config('kuking.wersja.plik_wydania');

        if (! is_string($sciezka) || ! is_file($sciezka)) {
            return '';
        }

        $tresc = @file_get_contents($sciezka);

        return is_string($tresc) ? trim($tresc) : '';
    }

    /** Etap produktu — podbijany ręcznie, razem z ROADMAP.md. */
    public static function etykieta(): string
    {
        return (string) config('kuking.wersja.etykieta');
    }

    /**
     * Kotwica strony „Co nowego” (`nowosci.index`) dla BIEŻĄCEGO wydania —
     * issue #1909: kliknięcie wersji w stopce ma otworzyć tę stronę OD RAZU
     * przy opisie wydania, na które ktoś patrzy, nie od góry dokumentu.
     *
     * `resources/nowosci/tresc.md` ma nagłówek `## Alfa 0.68` dla tego
     * wydania — BEZ podtytułu w samym nagłówku (podtytuł stoi zdaniem pod
     * nim), właśnie po to, żeby jego kotwica dała się policzyć z SAMEJ
     * etykiety. Liczymy ją algorytmem slugów GitHuba (GFM), tym samym, co
     * `tests/Feature/DokumentyMdNieMajaMartwychOdnosnikowTest.php` używa do
     * sprawdzania odnośników we WSZYSTKICH plikach `.md` repozytorium —
     * ten plik nie jest wyjątkiem, więc kotwica MUSI się z nim zgadzać,
     * inaczej ten ogólny strażnik i ten, węższy, przestają się zgadzać.
     * Algorytm: małe litery, spacja → myślnik, potem zostają tylko litery
     * (Unicode), cyfry, myślniki i podkreślenia — reszta (kropka, myślnik
     * długi…) znika BEZ ZASTĘPCZEGO ZNAKU. „Alfa 0.68” → „alfa-068”.
     *
     * Nie używamy tu skrótu `Str::slug()` z innym zachowaniem separatorów —
     * ważne jest, żeby dać DOKŁADNIE ten sam wynik co test wyżej, nie
     * „podobny”.
     */
    public static function kotwicaWydania(): string
    {
        $wynik = SlugGfm::z(self::etykieta());

        return $wynik !== '' ? $wynik : 'najnowsze-zmiany';
    }

    /**
     * PEŁNY SHA wdrożonego commita albo `null`, gdy nic nie wdrożono.
     *
     * Dla maszyn (`/wydanie`, test dymny po wdrożeniu — issue #1012), nie dla
     * ludzi: skrót jest niejednoznaczny, a sonda ma porównać DOKŁADNIE ten
     * commit, który miał zostać wdrożony.
     */
    public static function commit(): ?string
    {
        $commit = config('kuking.wersja.commit');

        return is_string($commit) && trim($commit) !== '' ? strtolower(trim($commit)) : null;
    }

    /** Skrót wdrożonego commita albo „lokalnie", gdy nic nie wdrożono. */
    public static function wydanie(): string
    {
        $commit = config('kuking.wersja.commit');

        if (! is_string($commit) || $commit === '') {
            return 'lokalnie';
        }

        return substr($commit, 0, self::DLUGOSC_SKROTU);
    }

    /**
     * Etykieta z KOŃCÓWKĄ wdrożenia, np. „Alfa 0.68.005" — issue #1932,
     * D-318. Bez wiersza w `wdrozenia` dla bieżącego commita (lokalnie,
     * w testach, przy awarii bazy albo przed pierwszym uruchomieniem
     * `kuking:zarejestruj-wdrozenie` na tym commicie) zostaje SAMA etykieta,
     * bez błędu — dokładnie ten sam wybór co przy braku znacznika daty.
     *
     * CELOWO NIE JEST TYM, CO ZWRACA `etykieta()`. `etykieta()` musi
     * zostać czystą wartością z `config/kuking.php` — czyta ją dosłownie
     * `PodbicieWersjiWymagaWpisuWChangelogTest`, porównując z nagłówkiem
     * CHANGELOG-a w formacie `Alfa 0.N` / `Beta 0.N`, BEZ końcówki. Gdyby
     * `etykieta()` doklejała numer, ten strażnik przestałby cokolwiek
     * sprawdzać.
     */
    public static function etykietaZNumerem(): string
    {
        $numer = self::numerWdrozenia();

        if ($numer === null) {
            return self::etykieta();
        }

        return self::etykieta().'.'.str_pad((string) $numer, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Końcówka wdrożenia (`wdrozenia.numer`) dla BIEŻĄCEGO commita, albo
     * `null`, gdy nie wiadomo (brak commita, brak wiersza, baza
     * niedostępna). Z CACHE'M — ta metoda woła się z KAŻDEJ stopki, na
     * każdej stronie serwisu, więc bez cache'u byłoby to jedno dodatkowe
     * zapytanie do bazy na każde żądanie, na zawsze (issue #1932 wprost
     * tego wymaga: „stopka … z cache").
     *
     * Klucz cache'u niesie sam commit — inny commit (nowe wdrożenie) sam
     * unieważnia poprzedni wpis, bez ręcznego czyszczenia. TTL jest długi
     * (commit się przecież nie zmienia pod tym samym wdrożeniem), ale
     * SKOŃCZONY: gdyby coś zarejestrowało wiersz PO tym, jak ta metoda już
     * raz zwróciła `null` i to zapisała w cache'u (np. wyścig przy starcie),
     * błąd naprawia się sam po wygaśnięciu, bez restartu procesu.
     *
     * BRAK TABELI/BAZY NIE WYWALA STOPKI — ten sam wybór co przy
     * `dataWydania()`: uszkodzone albo niedostępne źródło ma zabrać jedną
     * informację, nie całą stronę (łącznie ze stroną logowania).
     */
    public static function numerWdrozenia(): ?int
    {
        $commit = self::commit();

        if ($commit === null) {
            return null;
        }

        try {
            return Cache::remember('kuking:wersja:numer:'.$commit, now()->addMinutes(10), static function () use ($commit): ?int {
                $numer = DB::table('wdrozenia')->where('commit', $commit)->value('numer');

                return is_int($numer) ? $numer : (is_numeric($numer) ? (int) $numer : null);
            });
        } catch (\Throwable) {
            return null;
        }
    }
}
