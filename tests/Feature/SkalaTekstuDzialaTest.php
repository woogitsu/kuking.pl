<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Każda skala tekstu z ustawień naprawdę zmienia rozmiar liter.
 *
 * CO BYŁO ZMIERZONE (8 września 2026)
 * Trzy miejsca opisywały to samo ustawienie i nie zgadzały się ze sobą:
 *
 *   config/kuking.php   'scales' => [100, 112, 125, 140]      — co widzi człowiek
 *   resources/css/…     112, 125, 150                          — co umie arkusz
 *   migracja `users`    CHECK (text_scale BETWEEN 90 AND 140)  — co przyjmie baza
 *
 * Skutek: **140 zapisywało się na koncie i nie robiło nic.** W widoku
 * `/ustawienia/czytelnosc` ta opcja jest podpisana „Bardzo duży", czyli
 * wybiera ją osoba, której 18 px już nie wystarcza — i dostawała 100%.
 * W drugą stronę 150 w arkuszu było martwe, bo CHECK w migracji nie pozwala
 * takiej wartości powstać.
 *
 * DLACZEGO TEST, A NIE SAMA POPRAWKA
 * Ten rozjazd jest niewidoczny i milczy. Brakująca reguła nie jest błędem
 * CSS — przeglądarka po prostu zostaje przy wartości domyślnej. Nie zgłosi
 * tego ani build, ani Larastan, ani automat dostępności, bo strona renderuje
 * się poprawnie; jest tylko mniejsza, niż człowiek prosił. Jedyne, co to
 * łapie, to porównanie konfiguracji z arkuszem.
 */
class SkalaTekstuDzialaTest extends TestCase
{
    /**
     * Wartość domyślna `--user-text-scale` to 1, więc 100% z definicji nie
     * potrzebuje własnej reguły i jej brak nie jest usterką.
     */
    private const BEZ_WLASNEJ_REGULY = 100;

    private function arkusz(): string
    {
        $sciezka = resource_path('css/tokens.css');

        $this->assertFileExists($sciezka, 'Arkusz z tokenami zniknął — test nie ma czego sprawdzać.');

        return (string) file_get_contents($sciezka);
    }

    /**
     * @return list<int>
     */
    private function skaleZKonfiguracji(): array
    {
        /** @var list<int> $skale */
        $skale = array_map('intval', (array) config('kuking.text.scales'));

        // ASERCJA KONTROLNA. Bez niej pusta albo przemianowana konfiguracja
        // dałaby test, który nie sprawdza ani jednej skali i świeci na zielono.
        $this->assertNotEmpty($skale, 'config(kuking.text.scales) jest puste — test nie mierzy niczego.');
        $this->assertContains(
            140,
            $skale,
            'Największa skala zniknęła z konfiguracji. Jeśli to celowe, popraw ten test razem z decyzją.',
        );

        return $skale;
    }

    public function test_kazda_oferowana_skala_ma_regule_w_arkuszu(): void
    {
        $arkusz = $this->arkusz();

        foreach ($this->skaleZKonfiguracji() as $skala) {
            if ($skala === self::BEZ_WLASNEJ_REGULY) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\[data-text-scale="'.$skala.'"\]\s*\{[^}]*--user-text-scale:/',
                $arkusz,
                "Skala {$skala}% jest do wyboru w ustawieniach, ale arkusz jej nie zna — "
                .'człowiek ją wybierze, ustawienie się zapisze i tekst zostanie taki sam.',
            );
        }
    }

    /**
     * Każdy oferowany rozmiar ma podpis słowem.
     *
     * Do 11 września podpisy siedziały w widoku jako łańcuch `@if/@elseif`
     * z gałęzią `@else` na końcu — więc każdy nowy rozmiar dostawał podpis
     * ostatniego („Bardzo duży") i NIC BY TEGO NIE ZGŁOSIŁO. Po przeniesieniu
     * ich do `kuking.text.scale_labels` brak podpisu jest po prostu brakiem
     * klucza, a to daje się sprawdzić.
     */
    public function test_kazdy_rozmiar_ma_podpis_slowem(): void
    {
        /** @var array<int, string> $podpisy */
        $podpisy = (array) config('kuking.text.scale_labels');

        $this->assertNotEmpty($podpisy, 'config(kuking.text.scale_labels) jest puste — test nie mierzy niczego.');

        foreach ($this->skaleZKonfiguracji() as $skala) {
            $this->assertArrayHasKey(
                $skala,
                $podpisy,
                "Rozmiar {$skala}% jest do wyboru w ustawieniach, ale nie ma podpisu — "
                .'człowiek zobaczyłby w tym wierszu goły procent zamiast słowa.',
            );

            $this->assertNotSame('', trim($podpisy[$skala]), "Podpis rozmiaru {$skala}% jest pusty.");
        }
    }

    /**
     * Podpis bez rozmiaru jest martwym wpisem: sugeruje przy czytaniu, że
     * taka opcja istnieje, a ustawienia jej nie pokazują.
     */
    public function test_nie_ma_podpisu_bez_rozmiaru(): void
    {
        $skale = $this->skaleZKonfiguracji();

        foreach (array_keys((array) config('kuking.text.scale_labels')) as $podpisany) {
            $this->assertContains(
                (int) $podpisany,
                $skale,
                "Podpis opisuje rozmiar {$podpisany}%, którego nie ma w `kuking.text.scales`.",
            );
        }
    }

    public function test_arkusz_nie_zna_skali_ktorej_baza_nie_przyjmie(): void
    {
        $arkusz = $this->arkusz();
        $dozwolone = $this->skaleZKonfiguracji();

        preg_match_all('/\[data-text-scale="(\d+)"\]/', $arkusz, $trafienia);

        // ASERCJA KONTROLNA: reguły w ogóle są. Gdyby ktoś usunął całą sekcję,
        // pętla niżej nie wykonałaby się ani razu i test przeszedłby pusto.
        $this->assertNotEmpty($trafienia[1], 'W arkuszu nie ma ani jednej reguły data-text-scale.');

        foreach (array_unique(array_map('intval', $trafienia[1])) as $wArkuszu) {
            $this->assertContains(
                $wArkuszu,
                $dozwolone,
                "Arkusz opisuje skalę {$wArkuszu}%, której nie da się ustawić: nie ma jej "
                .'w konfiguracji, a CHECK w migracji `users` dopuszcza tylko 90–140. '
                .'Martwa reguła sugeruje przy czytaniu, że taka opcja istnieje.',
            );
        }
    }
}
