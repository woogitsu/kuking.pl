<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

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
 * SENTRY_RELEASE rzeczywiście jest ustawiane w `.railway/railway.ts`, ale
 * **Sentry'ego w tym projekcie nie ma**: nie ma pakietu w `composer.json`, nie
 * ma `config/sentry.php`, a `SENTRY_LARAVEL_DSN` nie czyta ani jedna linijka
 * PHP (D-041). Wpisów przy błędzie, do których ten skrót miałby pasować, nie
 * ma więc żadnych. Błędy 500 idą dziś na kanał `blad_webhook`, a ten wysyła
 * klasę wyjątku, plik:linię i wzorzec trasy — bez numeru wydania. Powiązanie
 * zgłoszenia z commitem robi się dziś ręcznie, przez stopkę, i to jest jedyny
 * powód, dla którego ten skrót w ogóle w niej stoi.
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
        return self::etykieta().' · '.self::opisWydania();
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

    /** Skrót wdrożonego commita albo „lokalnie", gdy nic nie wdrożono. */
    public static function wydanie(): string
    {
        $commit = config('kuking.wersja.commit');

        if (! is_string($commit) || $commit === '') {
            return 'lokalnie';
        }

        return substr($commit, 0, self::DLUGOSC_SKROTU);
    }
}
