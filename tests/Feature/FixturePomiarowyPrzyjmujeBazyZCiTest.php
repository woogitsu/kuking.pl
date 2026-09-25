<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fixture PHP przyjmuje te bazy, które job `dostepnosc` naprawdę mu podaje.
 *
 * CO SIĘ STAŁO
 * ------------
 * `.github/workflows/ci.yml` przestawił cztery kroki joba `dostepnosc` na
 * własne bazy jednorazowe (`kuking_kafel_pomiar`, `kuking_focus_pomiar`,
 * `kuking_widok_pomiar`, #736), żeby `migrate:fresh --seed` przestał kasować
 * `kuking_test` spod stojącego obok `php artisan test`.
 *
 * `scripts/bezpiecznik-bazy.mjs` te nazwy przyjął, bo rozpoznaje RODZINĘ
 * `…_pomiar`. Fixture'y PHP wołane przez te same skrypty miały jednak WŁASNE
 * listy dosłownych nazw i o `_pomiar` nic nie wiedziały. Krok axe-core padł:
 *
 *     Error: Command failed: php scripts/fixtures/karuzela-mieszana.php
 *     Karuzela431 wymaga izolowanej lokalnej bazy pomiarowej i lokalnego dysku public.
 *
 * Czerwień wyglądała na brak klienta PostgreSQL-a albo na nieistniejącą bazę
 * (`createdb` w `zalozBazeJesliTrzeba()` połyka błąd w ciszy). Nie była nią:
 * bazy powstały, a odmówił strażnik po stronie PHP.
 *
 * CZEGO TO PILNUJE
 * ----------------
 * Że nazwa bazy z `ci.yml` i strażnik w fixturze nie mogą się rozjechać
 * po cichu. Przy każdej kolejnej zmianie `DB_DATABASE` w tym jobie test
 * zapali się LOKALNIE, zanim zapali się przebieg CI za kilkadziesiąt minut.
 *
 * KONTROLA DODATNIA
 * -----------------
 * Skan, który nie znajduje żadnego pliku, przechodzi — dlatego niżej stoi
 * wymóg, żeby w jobie NAPRAWDĘ znalazły się co najmniej trzy różne nazwy,
 * i osobny przypadek sprawdzający, że strażnik wciąż KOGOŚ odrzuca.
 *
 * @see scripts/fixtures/baza-pomiarowa.php
 * @see scripts/bezpiecznik-bazy.mjs
 */
class FixturePomiarowyPrzyjmujeBazyZCiTest extends TestCase
{
    /** Bazy nazwane wprost przez `karuzela-mieszana.php` — poza rodziną `_pomiar`. */
    private const KARUZELA = ['kuking_a11y', 'kuking_test_a11y', 'kuking_proof431'];

    /** Bazy nazwane wprost przez `oauth-bootstrap.php`. */
    private const OAUTH = ['kuking_oauth345', 'kuking_test_a11y', 'kuking_a11y'];

    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('scripts/fixtures/baza-pomiarowa.php');
    }

    #[Test]
    public function test_kazda_baza_joba_dostepnosc_przechodzi_przez_straznika_fixtura(): void
    {
        $bazy = $this->bazyJobaDostepnosc();

        // Kontrola dodatnia: gdyby zmienił się układ `ci.yml` i wyrażenie
        // niżej przestało cokolwiek łapać, test byłby zielony przy zepsutym
        // jobie. Trzy nazwy to stan zmierzony: kafel, fokus, widok.
        $this->assertGreaterThanOrEqual(
            3,
            count($bazy),
            'W jobie `dostepnosc` w .github/workflows/ci.yml nie widzę trzech różnych '.
            'nazw `DB_DATABASE`. Albo zmienił się układ pliku, albo ten test przestał '.
            'cokolwiek czytać — w obu wypadkach nie pilnuje już niczego.',
        );

        foreach ($bazy as $baza) {
            $this->assertTrue(
                kukingWolnoUzycBazyFixture($baza, self::KARUZELA),
                "scripts/fixtures/karuzela-mieszana.php odrzuci bazę „{$baza}\", którą job ".
                '`dostepnosc` podaje krokowi axe-core. Dopisz ją do rodziny w '.
                'scripts/fixtures/baza-pomiarowa.php albo zmień nazwę w ci.yml.',
            );

            $this->assertTrue(
                kukingWolnoUzycBazyFixture($baza, self::OAUTH),
                "scripts/fixtures/oauth-bootstrap.php odrzuci bazę „{$baza}\" z joba `dostepnosc`.",
            );
        }
    }

    #[Test]
    public function test_straznik_nadal_odmawia_bazom_spoza_rodziny_jednorazowej(): void
    {
        $zakazane = [
            'kuking',                        // baza deweloperska
            'kuking_test',                   // baza testowa głównego checkoutu
            'kuking_test_kat_kontrakt_1a2b3c4d', // baza kopii bez `.git` (#920)
            'kuking_test_pomiar',            // odmowa jest silniejsza od zgody
            'kuking_race_a',
            'proba_odtworzenia_glowny',
            'kuking_zrodlo_proby_glowny',
            'railway_produkcja',
            'postgres',
            'cudza_baza',
            'KUKING_TEST',                   // Postgres składa do małych liter
        ];

        foreach ($zakazane as $baza) {
            $this->assertFalse(
                kukingJestBazaPomiarowa($baza),
                "Strażnik fixture'ów wpuściłby „{$baza}\", a fixture robi na bazie ".
                '`migrate:fresh --seed`, czyli kasuje wszystko, co w niej stoi.',
            );
        }
    }

    #[Test]
    public function test_oba_fixtury_wolaja_wspolnego_straznika_zamiast_wlasnej_listy(): void
    {
        // Sam strażnik może być bez zarzutu i nikomu niepotrzebny, jeśli
        // fixture'y wrócą do własnych list dosłownych nazw.
        foreach (['karuzela-mieszana.php', 'oauth-bootstrap.php'] as $plik) {
            $sciezka = base_path('scripts/fixtures/'.$plik);

            $this->assertFileExists($sciezka);

            $this->assertStringContainsString(
                'kukingWolnoUzycBazyFixture(',
                (string) file_get_contents($sciezka),
                "scripts/fixtures/{$plik} nie woła już wspólnego strażnika z ".
                'scripts/fixtures/baza-pomiarowa.php — wróciła osobna lista nazw, '.
                'czyli dokładnie to, co rozjechało się przy #736.',
            );
        }
    }

    /** @return list<string> nazwy `DB_DATABASE` z bloku joba `dostepnosc` */
    private function bazyJobaDostepnosc(): array
    {
        $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        // Blok joba: od `  dostepnosc:` do następnego klucza na tym wcięciu.
        if (! preg_match('/^  dostepnosc:\n(.*?)(?=^  [a-z][a-z0-9_-]*:\n)/ms', $ci, $blok)) {
            return [];
        }

        // Tylko `steps:` — nie `env:` całego joba. Na poziomie joba stoi
        // `kuking_test`, czyli baza kontenera `postgres` tego przebiegu;
        // fixture'y jej nie dostają, bo każdy z czterech kroków nadpisuje
        // `DB_DATABASE` własną bazą jednorazową.
        $kroki = strstr($blok[1], "\n    steps:\n");

        if ($kroki === false) {
            return [];
        }

        preg_match_all('/^\s*DB_DATABASE:\s*(\S+)\s*$/m', $kroki, $trafienia);

        return array_values(array_unique($trafienia[1]));
    }
}
