<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Automat dostępności ma mierzyć TEN SAM obiekt przy każdym przebiegu.
 *
 * CO BYŁO NIE TAK
 * ---------------
 * `scripts/dostepnosc.mjs` wybierał przykładowy przepis, wpis, konto
 * moderatora i konto zgłaszającego przez `->first()` albo `->value()` BEZ
 * `ORDER BY`. PostgreSQL nie obiecuje przy takim zapytaniu żadnej kolejności:
 * który wiersz wróci, zależy od planu, a plan zmienia się od liczby wierszy,
 * od `VACUUM` i od kolejności zapisu — czyli od rzeczy, których nie ma
 * w repozytorium.
 *
 * Skutek jest gorszy niż nieporządek. Raport nazywa EKRAN, nie próbkę, więc
 * czerwień czyta się jako skutek czyjejś zmiany, choć bywa skutkiem losu:
 * ktoś szuka pół dnia, co zepsuł, i nie zepsuł nic. Odwrotnie jest gorzej —
 * prawdziwa regresja chowa się za zdaniem „a, to pewnie ta zmienność".
 *
 * ZMIERZONE, NIE ZAŁOŻONE (12 września 2026). Karta wpisu autora o nazwie na
 * najdłuższej dopuszczalnej nazwie (`zofia_z_bieszczad`, #440) wchodziła do pomiaru fokusu BOCZNYMI
 * DRZWIAMI: na `EKRANY_FOCUS` nie ma jej ani razu, a mierzona była dlatego,
 * że pozycja „wpis (przykładowy)" rozwiązuje się przez `znajdz: 'wpis:normal'`
 * i to właśnie jej wpis oddawał `SELECT … LIMIT 1` bez `ORDER BY`. Dopisanie
 * nowszego wpisu do `DemoSeeder` wystarczyłoby, żeby ta próbka przestała
 * pilnować WCAG 2.2 AA 2.4.11 — bez jednego oblanego testu.
 *
 * CZEGO TEN TEST NIE ROBI
 * -----------------------
 * Nie uruchamia automatu i nie mierzy niczego w przeglądarce. Pilnuje
 * KSZTAŁTU zapytań w tym jednym pliku — czyli rzeczy, której sam pomiar
 * pilnować nie może: przebieg na niejednoznacznej próbce wygląda dokładnie
 * tak samo jak przebieg na jednoznacznej.
 */
class PomiarDostepnosciWybieraPowtarzalnieTest extends TestCase
{
    /**
     * Minimalna liczba zapytań, które ten skan MUSI znaleźć.
     *
     * Pułapka 2 z `docs/PULAPKI_TESTOW.md`: test skanujący przechodzi także
     * wtedy, gdy nie znajdzie NICZEGO — zero trafień jest dla niego sukcesem.
     * W chwili pisania skan widzi 23 zapytania; próg stoi wyraźnie niżej, żeby
     * nie pękał od skasowania jednego ekranu, i wyraźnie wyżej od zera.
     */
    private const MINIMUM_ZAPYTAN = 18;

    #[Test]
    public function test_kazde_zapytanie_wybierajace_probke_ma_jednoznaczna_kolejnosc(): void
    {
        $zapytania = $this->zapytaniaDoBazy();

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_ZAPYTAN,
            count($zapytania),
            'Skan znalazł w `scripts/dostepnosc.mjs` tylko '.count($zapytania).' zapytań do bazy. '.
            'Albo zmienił się sposób ich zapisu, albo wzorzec przestał je łapać — a skan, '.
            'który nie czyta niczego, przechodzi zawsze (pułapka 2).',
        );

        $bezKolejnosci = array_values(array_filter(
            $zapytania,
            fn (string $z): bool => ! $this->maJednoznacznaKolejnosc($z),
        ));

        $this->assertSame(
            [],
            $bezKolejnosci,
            "W `scripts/dostepnosc.mjs` są zapytania wybierające próbkę bez jednoznacznej \n".
            "kolejności. `SELECT … LIMIT 1` bez `ORDER BY` nie obiecuje w PostgreSQL, \n".
            "który wiersz wróci — mierzony obiekt potrafi się wtedy zmienić od samego \n".
            "dołożenia wierszy, bez jednej zmiany w kodzie. Dopisz `->orderBy('id')` \n".
            "(albo `->orderByDesc('published_at')->orderByDesc('id')`, gdy kolejność ma \n".
            "znaczyć „najnowsze”):\n  ".implode("\n  ", $bezKolejnosci),
        );
    }

    #[Test]
    public function test_karta_wpisu_z_dluga_nazwa_jest_przypieta_po_autorze_a_nie_po_kolejnosci(): void
    {
        $skrypt = $this->skrypt();

        // Zapytanie musi pytać o AUTORA — to jest cecha, której dopisanie
        // nowego wpisu do seedera nie zmienia. Wybór „najnowszy wpis w trybie
        // zwykłym" zmienia się od pierwszego takiego dopisania.
        $this->assertMatchesRegularExpression(
            '/Profile::where\(\x27username\x27,\x27\$\{KONTO_DLUGA_NAZWA\}\x27\)/u',
            $skrypt,
            'Automat nie szuka wpisu autora o najdłuższej dopuszczalnej nazwie po nazwie konta. '.
            'Bez tego karta z długą nazwą wchodzi do pomiaru tylko wtedy, gdy akurat '.
            'wypadnie na nią wybór „jakiś wpis w trybie zwykłym" — czyli dopóki nikt '.
            'nie doda nowszego wpisu.',
        );

        $this->assertMatchesRegularExpression(
            '/\{\s*nazwa:\s*\x27wpis \(autor o najdłuższej dopuszczalnej nazwie\)\x27,\s*adres:\s*null,\s*'.
            'znajdz:\s*\x27wpis-dluga-nazwa\x27/u',
            $skrypt,
            'Na liście EKRANY_FOCUS nie ma karty wpisu autora o najdłuższej dopuszczalnej nazwie. '.
            'To właśnie ta próbka znalazła trzy naruszenia WCAG 2.2 AA 2.4.11 przy #440 '.
            '— i do 12 września wchodziła do pomiaru wyłącznie przez przypadek.',
        );
    }

    /**
     * Zapytania do bazy wypisane z pliku, każde jako jeden łańcuch.
     *
     * Zapytania są w tym skrypcie kawałkami łańcuchów JavaScriptu sklejanymi
     * przez `+`, często przez kilka linii i z komentarzem w środku. Najpierw
     * więc SKLEJAMY je z powrotem, a dopiero potem szukamy wzorca — inaczej
     * skan widziałby urwane fragmenty i część zapytań by przeoczył.
     *
     * @return list<string>
     */
    private function zapytaniaDoBazy(): array
    {
        $sklejone = (string) preg_replace(
            '/["\'`][ \t]*(?:\r?\n[ \t]*\/\/[^\n]*)*\r?\n[ \t]*\+[ \t]*["\'`]/u',
            '',
            $this->skrypt(),
        );

        preg_match_all(
            '/App\\\\\\\\Models\\\\\\\\[A-Za-z]+::[^;\n]{0,500}?->(?:firstOrFail|first|value|get)\(/u',
            $sklejone,
            $trafienia,
        );

        return $trafienia[0];
    }

    /**
     * Czy z tego zapytania da się wskazać JEDEN wiersz bez zgadywania.
     *
     * Dwa sposoby i tylko te dwa:
     *  - jawne `orderBy`/`orderByDesc` — porządek po kluczu głównym (UUID v7,
     *    unikalny i niezmienny) albo po `published_at` z `id` jako rozstrzygnięciem
     *    remisu;
     *  - warunek na `username`, czyli na kolumnie z ograniczeniem UNIQUE —
     *    tam pasujący wiersz jest co najwyżej jeden i porządkowanie niczego
     *    nie zmienia.
     *
     * `created_at` NIE jest trzecim sposobem i celowo nie ma go na tej liście:
     * seeder zapisuje wiersze w jednej sekundzie, więc remis jest tam regułą,
     * nie wyjątkiem.
     */
    private function maJednoznacznaKolejnosc(string $zapytanie): bool
    {
        return preg_match('/->orderBy(Desc)?\(/u', $zapytanie) === 1
            || str_contains($zapytanie, "'username'");
    }

    private function skrypt(): string
    {
        $sciezka = base_path('scripts/dostepnosc.mjs');

        $this->assertFileExists($sciezka, 'Nie ma automatu dostępności — tego pliku pilnuje ten test.');

        return (string) file_get_contents($sciezka);
    }
}
