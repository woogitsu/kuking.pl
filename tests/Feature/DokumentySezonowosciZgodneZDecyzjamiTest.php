<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sezonowość w `SOUL.md` i `RETENTION_LOOPS.md` mówi to samo co D-021 i D-026
 * (issue #2083, audyt C-08).
 *
 * CO BYŁO NIE TAK
 * `SOUL.md` §4.6 i pętla 5 w `RETENTION_LOOPS.md` opisywały jako MVP tabelę
 * `seasonal_moments` i pasek „Teraz sezon na…” w `/odkryj`. Żadnego z nich
 * nie ma w kodzie, a D-026 odrzuciła nawet kolumnę `sezonowy` słowami
 * „Informacja zostaje w pliku.” D-021 zleciła poprawkę tylko `COLD_START.md`,
 * więc te dwa dokumenty zostały z planem opartym na tabeli, której świadomie
 * nie zbudowano — i następna osoba mogłaby ją zbudować „bo MVP”.
 *
 * CZEGO TEN TEST PILNUJE
 * Jednej sprawdzalnej maszynowo własności: dopóki decyzja mówi „informacja
 * zostaje w pliku”, żaden wiersz o `seasonal_moments` ani o sezonowym pasku
 * w Discover nie stoi w komórce **MVP** / **tak**, a oba fragmenty cytują
 * zdanie z D-026 dosłownie. Zdanie jest czytane z `docs/DECISIONS.md`, nie
 * przepisane do testu — jeśli właściciel zmieni decyzję, test oblewa
 * i wymusza ponowne uzgodnienie dokumentów, zamiast bronić starej wersji.
 *
 * @bez-kontroli-dodatniej Czyta dokumentację, nie źródła aplikacji; cichą zieleń wykluczają kontrole w samym teście: detektor musi złapać trzy dawne wiersze sprzed #2083, a brak sekcji albo zdania w D-026 oblewa.
 */
class DokumentySezonowosciZgodneZDecyzjamiTest extends TestCase
{
    /** Komórka tabeli, która czyta się jak „w MVP” albo „tak, wchodzi”. */
    private const KOMORKA_MVP = '/\|\s*\*\*(?:MVP|tak)\*\*\s*\|/u';

    /** Wiersze, które nie mogą stać jako MVP bez nowej decyzji. */
    private const KOTWICE_NIEZATWIERDZONE = ['seasonal_moments', 'Teraz sezon na', 'pasek w Discover'];

    private const ZDANIE_D026 = 'Informacja zostaje w pliku.';

    private function plik(string $sciezka): string
    {
        return (string) file_get_contents(base_path($sciezka));
    }

    /**
     * Treść od nagłówka `$poczatek` do następnego nagłówka tego samego albo
     * wyższego poziomu (dla `## D-026` — do następnego `## `, nie do `### `).
     */
    private function sekcja(string $tresc, string $poczatek): string
    {
        $od = strpos($tresc, $poczatek);
        $this->assertNotFalse($od, "Nie znaleziono sekcji „{$poczatek}”.");

        $reszta = substr($tresc, $od + strlen($poczatek));
        $poziom = strlen((string) strstr($poczatek, ' ', true));
        $do = preg_match('/^#{1,'.$poziom.'} /mu', $reszta, $m, PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1]
            : strlen($reszta);

        return $poczatek.substr($reszta, 0, $do);
    }

    /** @return list<string> wiersze łamiące regułę */
    private function wierszeNiezatwierdzoneJakoMvp(string $tresc): array
    {
        return array_values(array_filter(
            explode("\n", $tresc),
            function (string $w): bool {
                foreach (self::KOTWICE_NIEZATWIERDZONE as $kotwica) {
                    if (str_contains($w, $kotwica) && preg_match(self::KOMORKA_MVP, $w) === 1) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * Kontrola źródła: decyzje nadal mówią to, na czym opiera się reszta testu,
     * i tabeli nadal nie ma. Bez tego asercje niżej broniłyby dokumentów
     * także po zmianie decyzji albo po zbudowaniu tabeli.
     */
    public function test_kontrola_decyzje_d021_i_d026_mowia_to_co_cytuja_dokumenty(): void
    {
        $decyzje = $this->plik('docs/DECISIONS.md');

        $this->assertStringContainsString(
            self::ZDANIE_D026,
            $this->sekcja($decyzje, '## D-026 ·'),
            'D-026 nie mówi już „Informacja zostaje w pliku.” — decyzja o sezonowości '
            .'się zmieniła. Uzgodnij `docs/product/SOUL.md` §4.6 i pętlę 5 '
            .'w `docs/product/RETENTION_LOOPS.md` z nową decyzją, potem popraw ten test.',
        );

        $this->assertStringContainsString(
            'wąska lista tagów promowanych',
            $this->sekcja($decyzje, '## D-021 ·'),
            'D-021 nie opiera już roli redakcyjnej na tagach promowanych — sprawdź, '
            .'czy §4.6 `SOUL.md` nadal opisuje prawdziwy mechanizm.',
        );

        $migracje = implode("\n", array_map(
            fn (string $p): string => (string) file_get_contents($p),
            glob(base_path('database/migrations/*.php')) ?: [],
        ));
        $this->assertStringNotContainsString(
            'seasonal_moments',
            $migracje,
            'Powstała tabela `seasonal_moments`. Jeśli stoi za nią decyzja właściciela, '
            .'dokumenty mają opisać nowy stan, a ten test trzeba zmienić razem z nimi.',
        );
    }

    public function test_soul_i_retention_nie_stawiaja_niezatwierdzonej_sezonowosci_jako_mvp(): void
    {
        foreach (['docs/product/SOUL.md', 'docs/product/RETENTION_LOOPS.md'] as $plik) {
            $zle = $this->wierszeNiezatwierdzoneJakoMvp($this->plik($plik));

            $this->assertSame(
                [],
                $zle,
                "`{$plik}` znowu stawia `seasonal_moments` albo sezonowy pasek w Discover "
                .'jako MVP, choć D-026 zostawiła sezonowość w pliku, a automatyczne '
                .'wyświetlanie nie ma decyzji właściciela (issue #2083).',
            );
        }
    }

    public function test_paragraf_sezonu_i_petla_5_cytuja_decyzje_doslownie(): void
    {
        $fragmenty = [
            'SOUL.md §4.6' => $this->sekcja($this->plik('docs/product/SOUL.md'), '### 4.6 '),
            'RETENTION_LOOPS.md pętla 5' => $this->sekcja($this->plik('docs/product/RETENTION_LOOPS.md'), '### Pętla 5 '),
        ];

        foreach ($fragmenty as $gdzie => $tresc) {
            $this->assertStringContainsString(
                self::ZDANIE_D026,
                $tresc,
                "{$gdzie} nie cytuje zdania z D-026 „".self::ZDANIE_D026.'” — '
                .'fragment o sezonie ma mówić stan uzgodniony z decyzją.',
            );
            $this->assertStringContainsString('D-021', $tresc, "{$gdzie} nie odsyła do D-021.");
            $this->assertStringContainsString('D-026', $tresc, "{$gdzie} nie odsyła do D-026.");
        }
    }

    /**
     * Kontrola detektora w obie strony: dawne wiersze z dokumentów (sprzed
     * #2083, przepisane dosłownie) są łapane, a poprawiony zapis przechodzi.
     * Bez tego zielony wynik mógłby znaczyć tylko, że wzorzec nic nie łapie.
     */
    public function test_kontrola_detektor_lapie_dawny_zapis_i_przepuszcza_obecny(): void
    {
        $dawne = implode("\n", [
            '| **Kalendarz sezonowy jako dane, nie kod** | Nie widzi nic wprost. | Redakcja | S (tabela `seasonal_moments`: data od–do, hasło, teksty) | **MVP** | Trzeba |',
            '| **Pasek „Teraz sezon na…”** | W `/odkryj`: „**Teraz sezon na śliwki**” | Rozwiązuje | S | **MVP** | Przy pustej |',
            '| Pętla 5 (sezon jako dane: pytanie dnia, temat, pasek w Discover) | S | **tak** |',
        ]);
        $this->assertCount(3, $this->wierszeNiezatwierdzoneJakoMvp($dawne));

        $obecne = '| Pętla 5 (pasek w Discover) | — | **nie** — nierozstrzygnięte |'."\n"
            .'| Tabela `seasonal_moments` | — | **Nie w MVP** |';
        $this->assertSame([], $this->wierszeNiezatwierdzoneJakoMvp($obecne));
    }
}
