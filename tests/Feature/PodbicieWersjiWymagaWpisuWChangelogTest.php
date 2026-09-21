<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Wersja;
use Tests\TestCase;

/**
 * Podbicie etykiety wersji bez wpisu w CHANGELOG.md.
 *
 * PO CO TO JEST
 * `config/kuking.php` mówi wprost, trzy linie nad `'etykieta' => …`:
 * „KAŻDY PODBICIE CYFRY MA WPIS W `CHANGELOG.md` — jedno pilnuje drugiego."
 * Do 21 września 2026 tej reguły nie pilnowało NIC WYKONYWALNEGO. Wersja żyje
 * w dwóch miejscach — `config/kuking.php` i `CHANGELOG.md` — a jedyny kod,
 * który jej dotykał, czytał ją dynamicznie przez `Wersja::etykieta()`, więc
 * podbicie samego configu przechodziło na zielono w całej baterii.
 *
 * I przeszło: audyt z 21.09.2026 znalazł CZTERY gałęzie floty
 * (`gpt-ugotowalem-dostep`, `gpt-zalegle`, `gpt-zdjecia-limity`,
 * `gpt-zdjecia-publikacja`), z których każda podbiła `etykieta` z „Alfa 0.67"
 * na „Alfa 0.68", a ŻADNA nie dotknęła `CHANGELOG.md`. W dodatku żadna nie
 * zrobiła tego świadomie — podbicie wjechało skutkiem ubocznym commitu
 * odzyskującego stanowisko po awarii repozytorium.
 *
 * DLACZEGO TO NIE PORÓWNUJE Z GITEM
 * Runtime floty (`_wspolne/przygotuj-runtime.sh`) i obraz produkcyjny
 * (`.dockerignore` wycina `*.md` i `docs/`) nie mają dostępu do historii, a
 * czasem i do samego pliku. Test wymagający `git diff` chodziłby tylko tam,
 * gdzie jest `.git`. Zamiast pytać „czy wersja SIĘ ZMIENIŁA", pytamy
 * o niezmiennik, który historii nie potrzebuje: „czy AKTUALNA etykieta wersji
 * jest tą samą etykietą, którą zapowiada NAJŚWIEŻSZY wpis w CHANGELOG.md".
 * Gdy ktoś podbije `etykieta` i zapomni o wpisie, obie wartości się rozjeżdżają
 * i test pada — bez jednego wywołania gita. Gdy ktoś zmienia kod bez ruszania
 * wersji, obie zostają takie, jak były: reguła pilnuje PODBICIA, nie każdej
 * zmiany.
 *
 * DLACZEGO SĄ TU DWIE KONTROLE
 * Nazwa testu to obietnica, nie fakt. Skan, który nie znajdzie pliku albo
 * przeczyta zły, jest zielony tak samo jak skan, który nie znalazł usterki —
 * to repozytorium ma już za sobą test uderzający w `/home` i świecący na
 * zielono przez cały czas. Dlatego niżej stoją dwie kontrole:
 * KONTROLA DODATNIA dowodzi, że plik naprawdę jest czytany i rozumiany,
 * a KONTROLA UJEMNA — że podbicie etykiety bez wpisu NAPRAWDĘ to sprawdzenie
 * wywraca, a nie przechodzi po cichu.
 *
 * GDZIE TO CHODZI, A GDZIE NIE
 * Wszędzie, gdzie leży pełne drzewo repozytorium z `CHANGELOG.md`: CI, lokalny
 * `php artisan test`, runtime floty. NIE w obrazie produkcyjnym — `.dockerignore`
 * wycina `*.md`, a testy i tak się tam nie wykonują. To strażnik na etapie
 * przeglądu kodu i CI, nie na produkcji.
 */
class PodbicieWersjiWymagaWpisuWChangelogTest extends TestCase
{
    public function test_aktualna_etykieta_wersji_ma_wpis_na_gorze_changelog(): void
    {
        $skarga = $this->niezgodnosc(Wersja::etykieta());

        $this->assertNull($skarga, (string) $skarga);
    }

    /**
     * KONTROLA UJEMNA: podbicie etykiety bez dopisania wpisu MA wywrócić
     * sprawdzenie z testu wyżej.
     *
     * Bez tego testu cały plik mógłby być zielony dlatego, że `niezgodnosc()`
     * nigdy niczego nie zgłasza — i wyglądałby tak samo jak strażnik, który
     * naprawdę działa. Podbicie idzie tą samą drogą, którą poszłoby prawdziwe:
     * przez wartość konfiguracji, którą czyta `Wersja::etykieta()`.
     */
    public function test_podbicie_etykiety_bez_wpisu_zostaje_wykryte(): void
    {
        $przed = Wersja::etykieta();
        $this->assertNull($this->niezgodnosc($przed), 'Punkt wyjścia ma być zgodny, inaczej ten test niczego nie dowodzi.');

        $podbita = $this->etykietaPodbitaOJeden($przed);
        $this->assertNotSame($przed, $podbita, 'Podbicie musi dać INNY numer, inaczej nie ma czego wykrywać.');

        config(['kuking.wersja.etykieta' => $podbita]);
        $skarga = $this->niezgodnosc(Wersja::etykieta());

        $this->assertNotNull(
            $skarga,
            "Etykieta podbita na „{$podbita}” bez wpisu w CHANGELOG.md przeszła bez skargi — ".
            'ten strażnik nie pilnuje niczego.',
        );
        $this->assertStringContainsString($podbita, $skarga, 'Skarga ma mówić, jaka wersja stoi w configu.');
        $this->assertStringContainsString($przed, $skarga, 'Skarga ma mówić, co zapowiada CHANGELOG.md.');
    }

    /**
     * KONTROLA DODATNIA: `etykietaNajnowszegoWpisu()` naprawdę czyta plik
     * i naprawdę rozumie jego nagłówek. Skan, który nie trafia w plik, zwraca
     * `null` albo pusty ciąg — i przy odrobinie pecha zrównałby się z czymkolwiek.
     */
    public function test_skan_naprawde_czyta_naglowek_changelog(): void
    {
        $sciezka = base_path('CHANGELOG.md');
        $this->assertFileExists($sciezka, 'CHANGELOG.md musi leżeć w drzewie repozytorium.');
        $this->assertNotSame('', trim((string) file_get_contents($sciezka)), 'CHANGELOG.md nie może być pusty.');

        $najnowszyWpis = $this->etykietaNajnowszegoWpisu();

        $this->assertNotNull($najnowszyWpis);
        $this->assertMatchesRegularExpression(
            '/^(Alfa|Beta) \d+\.\d+$/',
            $najnowszyWpis,
            'Nagłówek CHANGELOG.md ma zaczynać się etykietą w znanym formacie („Alfa 0.N" / '.
            '„Beta 0.N"). Gdy to nie przechodzi, skan czyta zły plik albo zły format — '.
            'a nie „brak zmian".',
        );
    }

    /**
     * Druga kontrola dodatnia: sprawdzamy TĘ etykietę, która stoi w pliku
     * `config/kuking.php`, a nie jakąś wartość doklejoną gdzie indziej.
     *
     * `Wersja::etykieta()` czyta konfigurację, a konfiguracja może przyjść ze
     * zbuforowanego `bootstrap/cache/config.php` albo z `config()` ustawionego
     * w teście. Gdyby to kiedyś się rozjechało, strażnik wyżej pilnowałby
     * czegoś innego niż linijki, nad którą stoi reguła — i nadal byłby zielony.
     */
    public function test_etykieta_widziana_przez_aplikacje_pochodzi_z_pliku_configu(): void
    {
        $sciezka = base_path('config/kuking.php');
        $this->assertFileExists($sciezka);

        $zrodlo = (string) file_get_contents($sciezka);
        $this->assertSame(
            1,
            preg_match("/^\s*'etykieta' => '([^']+)',/mu", $zrodlo, $dopasowanie),
            "W config/kuking.php nie ma dokładnie jednej linii „'etykieta' => '…',” — ".
            'strażnik wersji nie ma czego pilnować.',
        );

        $this->assertSame(
            $dopasowanie[1],
            Wersja::etykieta(),
            'Aplikacja pokazuje inną etykietę niż ta zapisana w config/kuking.php.',
        );
    }

    // -----------------------------------------------------------------
    // Narzędzia
    // -----------------------------------------------------------------

    /**
     * Skarga do człowieka, gdy podana etykieta nie ma swojego wpisu na górze
     * `CHANGELOG.md`. `null`, gdy wszystko się zgadza.
     */
    private function niezgodnosc(string $etykieta): ?string
    {
        $najnowszyWpis = $this->etykietaNajnowszegoWpisu();

        if ($najnowszyWpis === null) {
            return 'CHANGELOG.md nie ma ani jednego nagłówka „## …" — nie da się sprawdzić, '.
                "czy wersja „{$etykieta}” ma swój wpis.";
        }

        if ($najnowszyWpis === $etykieta) {
            return null;
        }

        return "config/kuking.php mówi „{$etykieta}”, ale najświeższy wpis w CHANGELOG.md ".
            "mówi „{$najnowszyWpis}”. Podbicie etykiety wersji MA mieć własny wpis na górze ".
            "CHANGELOG.md — patrz komentarz trzy linie nad 'etykieta' w config/kuking.php.";
    }

    /**
     * Pierwszy nagłówek `## …` w CHANGELOG.md, obcięty do samej etykiety wersji
     * (część przed „ — "). `null`, gdy pliku nie da się przeczytać albo nie ma
     * w nim żadnego nagłówka drugiego poziomu.
     */
    private function etykietaNajnowszegoWpisu(): ?string
    {
        $sciezka = base_path('CHANGELOG.md');

        if (! is_file($sciezka)) {
            return null;
        }

        $tresc = file_get_contents($sciezka);

        if ($tresc === false) {
            return null;
        }

        if (! preg_match('/^##\s+(.+?)\s+—/mu', $tresc, $dopasowanie)) {
            return null;
        }

        return trim($dopasowanie[1]);
    }

    /** „Alfa 0.67" → „Alfa 0.68". Tylko na użytek kontroli ujemnej. */
    private function etykietaPodbitaOJeden(string $etykieta): string
    {
        return (string) preg_replace_callback(
            '/(\d+)$/',
            static fn (array $cyfry): string => (string) ((int) $cyfry[1] + 1),
            $etykieta,
            1,
        );
    }
}
