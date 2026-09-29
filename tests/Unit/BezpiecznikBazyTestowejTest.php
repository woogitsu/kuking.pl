<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bezpiecznik z D-334: PHPUnit odmawia startu na bazie spoza rodziny testowej.
 *
 * Sprawdza samą decyzję (`kuking_ocen_baze_testowa()`), bez łączenia się z
 * jakąkolwiek bazą — ani deweloperską, ani testową. Funkcja jest czysta: dostaje
 * nazwę i adres, zwraca null (wolno) albo komunikat (odmowa). Że bootstrap ją
 * NAPRAWDĘ woła, dowodzi `Tests\Feature\BezpiecznikBazyTestowejStartTest`.
 *
 * Świadomie `PHPUnit\Framework\TestCase` — ten test nie potrzebuje aplikacji.
 */
class BezpiecznikBazyTestowejTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function bazyZRodzinyTestowej(): iterable
    {
        yield 'główny checkout' => ['kuking_test'];
        yield 'worktree' => ['kuking_test_agent_ae49a13904e048bb3'];
        yield 'kopia bez .git' => ['kuking_test_kat_kuking_pl_1a2b3c4d'];
        yield 'grupa dwa-polaczenia, główny checkout' => ['kuking_race'];
        yield 'grupa dwa-polaczenia, worktree' => ['kuking_race_agent_ae49a13904e048bb3'];
        yield 'stanowisko floty z myślnikiem' => ['kuking_flota_gpt-onboarding'];
        yield 'stanowisko floty, kontrole ujemne' => ['kuking_flota_stanowisko_1'];
    }

    /** @return iterable<string, array{string}> */
    public static function bazyPozaRodzina(): iterable
    {
        yield 'deweloperska' => ['kuking'];
        yield 'produkcyjna Railway' => ['railway'];
        yield 'domyślna Laravela' => ['laravel'];
        yield 'systemowa' => ['postgres'];
        yield 'szablon' => ['template1'];
        yield 'cudza baza próby odtworzenia' => ['proba_odtworzenia_20260929'];
        yield 'baza przyrządu, nie testów' => ['kuking_a11y'];
        yield 'baza pomiarowa' => ['kuking_port_pomiar'];
        yield 'baza panelu' => ['kuking_581_browser'];
        yield 'sam prefiks bez rodziny' => ['kuking_testy'];
        yield 'prefiks przyklejony do innej nazwy' => ['kuking_testowa_produkcja'];
        yield 'rodzina flota bez stanowiska' => ['kuking_flota_'];
        yield 'wielkie litery to inna baza dla libpq' => ['KUKING_TEST'];
        yield 'zawiera rodzinę w środku' => ['produkcja_kuking_test'];
        yield 'nazwa ze znakiem specjalnym' => ['kuking_test; DROP DATABASE kuking'];
    }

    #[DataProvider('bazyZRodzinyTestowej')]
    public function test_baza_z_rodziny_testowej_jest_dopuszczona(string $baza): void
    {
        $this->assertNull(kuking_ocen_baze_testowa($baza));
    }

    #[DataProvider('bazyPozaRodzina')]
    public function test_baza_spoza_rodziny_jest_odrzucona_z_komunikatem_po_polsku(string $baza): void
    {
        $komunikat = kuking_ocen_baze_testowa($baza);

        $this->assertIsString($komunikat);
        $this->assertStringContainsString('ODMAWIAM STARTU', $komunikat);
        $this->assertStringContainsString('Co zrobić', $komunikat);
        $this->assertStringContainsString('unset DB_DATABASE DB_URL', $komunikat);
        $this->assertStringContainsString('kuking_test', $komunikat);
    }

    public function test_pusta_nazwa_jest_odrzucona(): void
    {
        $this->assertIsString(kuking_ocen_baze_testowa(''));
        $this->assertIsString(kuking_ocen_baze_testowa('   '));
    }

    public function test_db_url_przebija_db_database(): void
    {
        // Nazwa w DB_DATABASE jest poprawna, ale DB_URL kieruje połączenie gdzie indziej —
        // tak działa config/database.php i tak ma to oceniać bezpiecznik.
        $komunikat = kuking_ocen_baze_testowa('kuking_test', 'postgres://kuking:sekret@db.example:5432/railway');

        $this->assertIsString($komunikat);
        $this->assertStringContainsString('DB_URL', $komunikat);
        $this->assertStringContainsString('railway', $komunikat);
        $this->assertStringNotContainsString('sekret', $komunikat, 'Hasło z adresu nie może trafić do komunikatu.');
    }

    public function test_db_url_z_baza_testowa_jest_dopuszczony(): void
    {
        $this->assertNull(kuking_ocen_baze_testowa('kuking', 'postgres://kuking:kuking@127.0.0.1:5432/kuking_test_wt'));
    }

    public function test_db_url_bez_nazwy_bazy_lub_nieczytelny_jest_odrzucony(): void
    {
        $this->assertIsString(kuking_ocen_baze_testowa('kuking_test', 'postgres://kuking:kuking@127.0.0.1:5432'));
        $this->assertIsString(kuking_ocen_baze_testowa('kuking_test', 'postgres://kuking:kuking@127.0.0.1:5432/'));
        $this->assertIsString(kuking_ocen_baze_testowa('kuking_test', 'http:///'));
    }

    public function test_pusty_db_url_jest_pomijany(): void
    {
        // phpunit.xml ustawia DB_URL na pusty napis — to ma znaczyć „brak adresu".
        $this->assertNull(kuking_ocen_baze_testowa('kuking_test', ''));
        $this->assertNull(kuking_ocen_baze_testowa('kuking_test', '   '));
    }

    public function test_nazwa_z_adresu_jest_dekodowana(): void
    {
        $this->assertSame('kuking_test_x', kuking_baza_z_adresu_polaczenia('postgres://u:p@h:5432/kuking%5Ftest%5Fx'));
        $this->assertNull(kuking_baza_z_adresu_polaczenia('kuking_test'), 'Bez schematu to nie jest adres połączenia.');
        $this->assertNull(kuking_baza_z_adresu_polaczenia('http:///'));
    }

    public function test_kazda_nazwa_wyliczana_przez_repozytorium_jest_z_rodziny(): void
    {
        // Reguła nazywania i bezpiecznik muszą się zgadzać: gdyby ktoś zmienił
        // jedną bez drugiej, KAŻDY przebieg testów w worktree byłby odmową.
        $korzen = dirname(__DIR__, 2);

        $this->assertNull(kuking_ocen_baze_testowa(kuking_nazwa_testowej_bazy($korzen)));
        $this->assertNull(kuking_ocen_baze_testowa(kuking_nazwa_bazy_wyscigow($korzen)));

        // Trzy przypadki reguły, także te, których to drzewo nie reprezentuje.
        $glowny = sys_get_temp_dir().'/kuking-bezp-'.bin2hex(random_bytes(4));
        $worktree = sys_get_temp_dir().'/kuking-bezp-'.bin2hex(random_bytes(4));
        $bezGit = sys_get_temp_dir().'/Kuking Kopia.'.bin2hex(random_bytes(4));

        try {
            mkdir($glowny.'/.git', 0o777, true);
            mkdir($worktree, 0o777, true);
            file_put_contents($worktree.'/.git', "gitdir: /repo/.git/worktrees/Agent-Ab_12\n");
            mkdir($bezGit, 0o777, true);

            foreach ([$glowny, $worktree, $bezGit] as $katalog) {
                $this->assertNull(
                    kuking_ocen_baze_testowa(kuking_nazwa_testowej_bazy($katalog)),
                    'Nazwa testowa dla '.basename($katalog).' wypadła poza rodzinę bezpiecznika.',
                );
                $this->assertNull(
                    kuking_ocen_baze_testowa(kuking_nazwa_bazy_wyscigow($katalog)),
                    'Nazwa wyścigowa dla '.basename($katalog).' wypadła poza rodzinę bezpiecznika.',
                );
            }
        } finally {
            @unlink($worktree.'/.git');
            @rmdir($glowny.'/.git');
            @rmdir($glowny);
            @rmdir($worktree);
            @rmdir($bezGit);
        }
    }
}
