<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DziennikDecyzji;
use Tests\TestCase;

/**
 * Dziennik decyzji po podziale (25.09.2026): jeden plik na decyzję
 * w `docs/decyzje/`, `docs/DECISIONS.md` jako indeks generowany z plików.
 *
 * Pilnuje, żeby:
 *  - numer w nazwie pliku był numerem w nagłówku „## D-NNN · …",
 *  - żaden numer nie miał dwóch plików (dwa PR-y z tym samym „następnym
 *    wolnym" nie dają konfliktu w gicie — dają dwa pliki),
 *  - tabela indeksu była dokładnie tym, co generuje `scripts/decyzje-indeks.php`,
 *  - w indeksie nie wylądowała treść decyzji — tak kończy się scalenie gałęzi,
 *    która dopisała wpis do starego, jednoplikowego dziennika.
 *
 * Metody `*_zapala_*` to kontrola z drugiej strony: te same reguły na
 * sztucznym katalogu w katalogu tymczasowym, z usterką wstawioną celowo.
 */
final class DziennikDecyzjiZgodnyZIndeksemTest extends TestCase
{
    private ?string $katalogTymczasowy = null;

    protected function tearDown(): void
    {
        if ($this->katalogTymczasowy !== null) {
            $this->usun($this->katalogTymczasowy);
        }

        parent::tearDown();
    }

    public function test_dziennik_w_repozytorium_nie_ma_usterek(): void
    {
        $dziennik = new DziennikDecyzji(base_path());

        // Pułapka 2 z docs/PULAPKI_TESTOW.md: pusty katalog nie ma usterek.
        $this->assertGreaterThan(200, count($dziennik->pliki()), 'Podejrzanie mało wpisów w '.DziennikDecyzji::KATALOG.'/.');

        $this->assertSame([], $dziennik->usterki(), implode("\n", $dziennik->usterki()));
    }

    public function test_czysty_sztuczny_dziennik_nie_ma_usterek(): void
    {
        $dziennik = $this->sztuczny();

        $this->assertSame([], $dziennik->usterki());
        $this->assertSame('D-003', $dziennik->nastepnyNumer());
    }

    public function test_zapala_przy_dwoch_plikach_z_tym_samym_numerem(): void
    {
        $dziennik = $this->sztuczny(['D-002-inna-decyzja.md' => "## D-002 · Inna decyzja\n\nTreść.\n"]);

        $this->assertUsterka($dziennik, 'D-002 ma 2 pliki');
    }

    public function test_zapala_gdy_numer_w_naglowku_rozni_sie_od_nazwy(): void
    {
        $dziennik = $this->sztuczny(['D-003-trzecia.md' => "## D-004 · Trzecia\n\nTreść.\n"]);

        $this->assertUsterka($dziennik, 'D-003-trzecia.md — pierwszy wiersz');
    }

    public function test_zapala_przy_dwoch_decyzjach_w_jednym_pliku(): void
    {
        $dziennik = $this->sztuczny(['D-003-trzecia.md' => "## D-003 · Trzecia\n\nTreść.\n\n## D-004 · Czwarta\n"]);

        $this->assertUsterka($dziennik, 'ma 2 nagłówków');
    }

    public function test_zapala_przy_nazwie_poza_wzorem(): void
    {
        $dziennik = $this->sztuczny(['D-003 Trzecia.md' => "## D-003 · Trzecia\n"]);

        $this->assertUsterka($dziennik, 'nazwa poza wzorem');
    }

    public function test_zapala_gdy_tabela_indeksu_nie_zgadza_sie_z_plikami(): void
    {
        $dziennik = $this->sztuczny();
        file_put_contents(
            $this->katalogTymczasowy.'/'.DziennikDecyzji::KATALOG.'/D-003-nowa-bez-indeksu.md',
            "## D-003 · Nowa bez indeksu\n\nStatus: **obowiązuje**\n",
        );

        $this->assertUsterka($dziennik, 'tabela indeksu nie zgadza się');

        // Generator naprawia dokładnie tę usterkę i niczego więcej nie rusza.
        $this->assertTrue($dziennik->odswiezIndeks());
        $this->assertSame([], $dziennik->usterki());
    }

    public function test_zapala_gdy_do_indeksu_dopisano_decyzje_w_starym_ukladzie(): void
    {
        $dziennik = $this->sztuczny();
        file_put_contents(
            $this->katalogTymczasowy.'/'.DziennikDecyzji::INDEKS,
            "\n## D-003 · Dopisana na końcu jak dawniej\n\nTreść.\n",
            FILE_APPEND,
        );

        $this->assertUsterka($dziennik, 'ma treść decyzji w starym układzie (D-003)');
    }

    public function test_status_i_tytul_trafiaja_do_wiersza_indeksu(): void
    {
        $wiersze = explode("\n", $this->sztuczny()->tabela());

        $this->assertContains(
            '| D-001 | Pierwsza \\| z kreską | obowiązuje | [D-001-pierwsza.md](decyzje/D-001-pierwsza.md) |',
            $wiersze,
        );
        $this->assertContains('| D-002 | Druga | — | [D-002-druga.md](decyzje/D-002-druga.md) |', $wiersze);
    }

    // ---------------------------------------------------------------- pomocnicze

    /** @param  array<string, string>  $dodatkowe  nazwa pliku => treść */
    private function sztuczny(array $dodatkowe = []): DziennikDecyzji
    {
        $this->katalogTymczasowy = sys_get_temp_dir().'/kuking-dziennik-'.bin2hex(random_bytes(6));
        mkdir($this->katalogTymczasowy.'/'.DziennikDecyzji::KATALOG, 0777, true);

        $pliki = [
            'D-001-pierwsza.md' => "## D-001 · Pierwsza | z kreską\n\n**Data:** 1 września 2026 · Status: **obowiązuje** · coś dalej\n",
            'D-002-druga.md' => "## D-002 · Druga\n\nBez statusu.\n\n### Podsekcja\n",
            'ADR_COS.md' => "# ADR, nie wpis dziennika\n\n## D-009 · to nie jest wpis\n",
        ];

        foreach ($pliki as $nazwa => $tresc) {
            file_put_contents($this->katalogTymczasowy.'/'.DziennikDecyzji::KATALOG.'/'.$nazwa, $tresc);
        }

        file_put_contents(
            $this->katalogTymczasowy.'/'.DziennikDecyzji::INDEKS,
            "# Dziennik decyzji\n\n".DziennikDecyzji::POCZATEK_TABELI."\n".DziennikDecyzji::KONIEC_TABELI."\n",
        );

        $dziennik = new DziennikDecyzji($this->katalogTymczasowy);
        $dziennik->odswiezIndeks();

        foreach ($dodatkowe as $nazwa => $tresc) {
            file_put_contents($this->katalogTymczasowy.'/'.DziennikDecyzji::KATALOG.'/'.$nazwa, $tresc);
        }

        return $dziennik;
    }

    private function assertUsterka(DziennikDecyzji $dziennik, string $fragment): void
    {
        $usterki = $dziennik->usterki();
        $trafione = array_filter($usterki, static fn (string $u): bool => str_contains($u, $fragment));

        $this->assertNotSame([], $trafione, 'Oczekiwano usterki z „'.$fragment."”. Są:\n".implode("\n", $usterki));
    }

    private function usun(string $sciezka): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sciezka, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $plik) {
            $plik->isDir() ? rmdir($plik->getPathname()) : unlink($plik->getPathname());
        }

        rmdir($sciezka);
    }
}
