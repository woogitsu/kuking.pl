<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DziennikDecyzji;
use Tests\TestCase;

/**
 * D-262 — ZAMKNIĘTA LISTA czterech selektorów panelu moderacji z pismem
 * poniżej 18 px (AGENTS.md §5, drugi nazwany wyjątek obok D-051).
 *
 * Audyt po fali 25.09 (pkt 8–9, propozycja B): AGENTS.md mówił o jednym
 * wyjątku, D-262 o drugim. Po decyzji właściciela §5 wymienia oba — a ten
 * test pilnuje, żeby lista w kodzie, w AGENTS.md i w D-262 się nie rozjechała
 * i żeby D-262 nie stało się furtką:
 *
 *  1. komentarz z „D-262” stoi WYŁĄCZNIE nad tymi czterema regułami CSS —
 *     piąty selektor nie może się na tę decyzję powołać bez zmiany listy
 *     (czyli bez nowej decyzji właściciela);
 *  2. każdy z czterech nadal ma pismo < 18 px — po podniesieniu do 18 px
 *     wyjątek jest martwy i trzeba go zdjąć z listy, AGENTS.md i D-262;
 *  3. w arkuszach wyłącznie panelowych nic innego nie ma pisma < 18 px;
 *  4. AGENTS.md §5 i wpis D-262 wymieniają te same cztery selektory.
 *
 * Czego NIE robi: nie mierzy piksela na ułożonej stronie (to robi
 * `scripts/audyt-ux50plus.mjs`) i nie ocenia pozostałych napisów 16 px
 * poza panelem (`.meta`, `.badge`…) — tym zajmuje się
 * `MinimalnyRozmiarTekstuTest` z własną listą.
 */
class WyjatekD262ZamknietaListaTest extends TestCase
{
    /** Selektor → arkusz. Zmiana tej listy = nowa decyzja właściciela. */
    private const LISTA_D262 = [
        '.side-nav-moderacja-naglowek' => 'resources/css/app.css',
        '.sygnal-podglad-cytat' => 'resources/css/app.css',
        '.tabela-kont .drobne' => 'resources/css/ekran-uzytkownikow.css',
        '.stan-konta' => 'resources/css/ekran-uzytkownikow.css',
    ];

    /** Arkusze, które stylują wyłącznie panel moderacji. */
    private const ARKUSZE_PANELU = [
        'resources/css/ekran-uzytkownikow.css',
        'resources/css/marka-panel.css',
    ];

    public function test_na_d262_powoluja_sie_tylko_cztery_selektory_z_listy(): void
    {
        $powolania = [];

        foreach (glob(base_path('resources/css/*.css')) ?: [] as $plik) {
            $wzgledna = 'resources/css/'.basename($plik);

            foreach ($this->reguly((string) file_get_contents($plik)) as $regula) {
                if (str_contains($regula['komentarz'], 'D-262')) {
                    $powolania[$regula['selektor']] = $wzgledna;
                }
            }
        }

        ksort($powolania);
        $oczekiwane = self::LISTA_D262;
        ksort($oczekiwane);

        $this->assertSame($oczekiwane, $powolania,
            'Na D-262 powołuje się inny zbiór reguł CSS niż cztery selektory z decyzji. '
            .'Piąty selektor wymaga nowej decyzji właściciela i dopisania go do AGENTS.md §5, '
            .'D-262 i LISTA_D262 — nie samego komentarza.');
    }

    public function test_kazdy_selektor_z_listy_nadal_ma_pismo_ponizej_18_px(): void
    {
        foreach (self::LISTA_D262 as $selektor => $arkusz) {
            $rozmiary = [];

            foreach ($this->reguly((string) file_get_contents(base_path($arkusz))) as $regula) {
                if ($regula['selektor'] === $selektor && $regula['rozmiar'] !== null) {
                    $rozmiary[] = $regula['rozmiar'];
                }
            }

            $this->assertNotSame([], $rozmiary, "Nie znaleziono `font-size` dla {$selektor} w {$arkusz}.");
            $this->assertTrue(self::ponizej18($rozmiary[0]),
                "{$selektor} ma już `{$rozmiary[0]}` — wyjątek D-262 jest martwy. "
                .'Zdejmij selektor z LISTA_D262, AGENTS.md §5 i D-262.');
        }
    }

    public function test_w_arkuszach_panelu_nic_poza_lista_nie_ma_pisma_ponizej_18_px(): void
    {
        $poza = [];

        foreach (self::ARKUSZE_PANELU as $arkusz) {
            $poza = [...$poza, ...$this->malePozaLista((string) file_get_contents(base_path($arkusz)), $arkusz)];
        }

        $this->assertSame([], $poza,
            'Pismo poniżej 18 px w panelu poza zamkniętą listą D-262 (AGENTS.md §5).');
    }

    public function test_agents_i_d262_wymieniaja_te_same_selektory(): void
    {
        $agents = (string) file_get_contents(base_path('AGENTS.md'));
        // Po podziale dziennika (#1744) wpis leży w docs/decyzje/; tresc() składa
        // pliki w jeden tekst jak dawny docs/DECISIONS.md.
        $dziennik = "\n".(new DziennikDecyzji(base_path()))->tresc();

        $start = strpos($dziennik, "\n## D-262 ");
        $this->assertNotFalse($start, 'Brak wpisu D-262 w '.DziennikDecyzji::KATALOG.'/.');
        $koniec = strpos($dziennik, "\n## D-", $start + 1);
        $wpis = substr($dziennik, $start, $koniec === false ? null : $koniec - $start);

        $this->assertStringContainsString('**D-262**', $agents, 'AGENTS.md §5 nie wymienia D-262 z nazwy.');

        foreach (array_keys(self::LISTA_D262) as $selektor) {
            $this->assertStringContainsString('`'.$selektor.'`', $agents, "AGENTS.md §5 nie wymienia {$selektor}.");
            $this->assertStringContainsString('`'.$selektor.'`', $wpis, "D-262 nie wymienia {$selektor}.");
        }
    }

    /**
     * KONTROLA DODATNIA na sztucznym arkuszu: piąty selektor z komentarzem
     * „D-262” i drobny napis bez komentarza w panelu mają zapalić — inaczej
     * testy wyżej byłyby zielone także przy parserze, który nic nie widzi.
     */
    public function test_parser_widzi_piaty_selektor_i_drobny_napis(): void
    {
        $css = <<<'CSS'
            @layer components {
              /* Świadomy wyjątek D-262 (napis pomocniczy w panelu). */
              .stan-konta {
                font-size: var(--text-meta);
              }

              /* Też D-262, choć nikt o tym nie decydował. */
              .nowa-plakietka { font-size: var(--text-help); }

              .zwykly-tekst { font-size: var(--text-body); }
              .ukradkiem-mniejszy { color: red; font-size: 15px; }
            }
            CSS;

        $powolania = array_values(array_map(
            fn (array $r): string => $r['selektor'],
            array_filter($this->reguly($css), fn (array $r): bool => str_contains($r['komentarz'], 'D-262')),
        ));

        $this->assertSame(['.stan-konta', '.nowa-plakietka'], $powolania);
        $this->assertSame(
            ['sztuczny.css: .nowa-plakietka (var(--text-help))', 'sztuczny.css: .ukradkiem-mniejszy (15px)'],
            $this->malePozaLista($css, 'sztuczny.css'),
        );
    }

    /** @return list<string> */
    private function malePozaLista(string $css, string $arkusz): array
    {
        $poza = [];

        foreach ($this->reguly($css) as $regula) {
            if ($regula['rozmiar'] !== null
                && self::ponizej18($regula['rozmiar'])
                && ! array_key_exists($regula['selektor'], self::LISTA_D262)) {
                $poza[] = "{$arkusz}: {$regula['selektor']} ({$regula['rozmiar']})";
            }
        }

        return $poza;
    }

    private static function ponizej18(string $rozmiar): bool
    {
        if (preg_match('/var\(--text-(meta|help)\)/', $rozmiar) === 1) {
            return true;
        }

        if (preg_match('/^([\d.]+)px$/', $rozmiar, $m) === 1) {
            return (float) $m[1] < 18;
        }

        if (preg_match('/^([\d.]+)rem$/', $rozmiar, $m) === 1) {
            return (float) $m[1] < 1.125;
        }

        return false;
    }

    /**
     * Najbardziej wewnętrzne reguły `selektor { … }` z komentarzem, który
     * stoi między poprzednią regułą a tą. Komentarze są wycinane przed
     * szukaniem klamer (mogą zawierać `{`), ale ich treść zostaje w polu
     * `komentarz`.
     *
     * @return list<array{selektor: string, komentarz: string, rozmiar: ?string}>
     */
    private function reguly(string $css): array
    {
        $komentarze = [];
        $bezKomentarzy = (string) preg_replace_callback('#/\*.*?\*/#s', function (array $m) use (&$komentarze): string {
            $komentarze[] = $m[0];

            return '/*'.(count($komentarze) - 1).'*/';
        }, $css);

        preg_match_all('/([^{};]+)\{([^{}]*)\}/', $bezKomentarzy, $trafienia, PREG_SET_ORDER);

        $wynik = [];

        foreach ($trafienia as $t) {
            $komentarz = '';
            $selektor = (string) preg_replace_callback('#/\*(\d+)\*/#', function (array $m) use (&$komentarz, $komentarze): string {
                $komentarz .= $komentarze[(int) $m[1]]."\n";

                return '';
            }, $t[1]);

            $rozmiar = preg_match('/font-size\s*:\s*([^;]+)/', (string) preg_replace('#/\*\d+\*/#', '', $t[2]), $m) === 1
                ? trim($m[1])
                : null;

            $wynik[] = [
                'selektor' => trim((string) preg_replace('/\s+/', ' ', $selektor)),
                'komentarz' => $komentarz,
                'rozmiar' => $rozmiar,
            ];
        }

        return $wynik;
    }
}
