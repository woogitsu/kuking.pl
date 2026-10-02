<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\SlownikTerminow;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wyjaśnienia terminów kulinarnych na żądanie w trybie gotowania (#2343).
 *
 * Wariant najostrożniejszy: statyczny słownik z repozytorium, zwykły
 * `<details>`, bez AI, bez zapisu i bez śledzenia. Tryb gotowania jest
 * mierzony pod kątem dostępności przez `scripts/dostepnosc.mjs`
 * (ekran „tryb gotowania”); krok demo „Zagotuj … zbierz łyżką szumowiny”
 * sprawia, że pomiar widzi także ten blok.
 */
class TerminyKulinarneWTrybieGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private function przepisZKrokiem(string $tresc): Recipe
    {
        $autor = User::factory()->create();
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => $tresc]);

        return $recipe;
    }

    public function test_krok_ze_znanym_terminem_ma_rozwijane_wyjasnienie_dla_goscia_bez_js(): void
    {
        $recipe = $this->przepisZKrokiem('Zrób zasmażkę z masła i mąki, potem zredukuj sos.');

        $html = (string) $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<details class="cook-terminy"', $html);
        $this->assertStringContainsString('Wyjaśnij to (2)', $html);
        $this->assertStringContainsString('Mąka podsmażona na tłuszczu', $html);
        $this->assertStringContainsString('Gotować sos albo wywar bez przykrycia', $html);
        // Treść kroku zostaje nietknięta.
        $this->assertStringContainsString('Zrób zasmażkę z masła i mąki, potem zredukuj sos.', $html);
    }

    public function test_krok_bez_znanego_terminu_nie_ma_pustego_przycisku(): void
    {
        $recipe = $this->przepisZKrokiem('Pokrój cebulę i wsyp do garnka.');

        $this->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('cook-terminy', false)
            ->assertDontSee('Wyjaśnij to');
    }

    public function test_blok_nie_niesie_zadnego_sledzenia_ani_skryptu_ani_formularza(): void
    {
        $recipe = $this->przepisZKrokiem('Zahartuj śmietanę.');

        $html = (string) $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<details class="cook-terminy".*?<\/details>/s', $html, $blok));
        $this->assertStringNotContainsString('<form', $blok[0]);
        $this->assertStringNotContainsString('<script', $blok[0]);
        $this->assertStringNotContainsString('onclick', $blok[0]);
        $this->assertStringNotContainsString('data-analityka', $blok[0]);
    }

    public function test_rozpoznaje_odmiane_i_nie_lapie_przypadkowych_slow(): void
    {
        $nazwy = fn (string $tekst): array => array_column(SlownikTerminow::wTekscie($tekst), 'haslo');

        $this->assertSame(['Zasmażka'], $nazwy('Do zasmażki dodaj bulion.'));
        $this->assertSame(['Hartowanie'], $nazwy('Zahartowaną śmietanę wlej do zupy.'));
        $this->assertSame(['Szumowiny', 'Redukować'], $nazwy('Zbierz szumowiny, a potem Redukuj płyn.'));
        $this->assertSame([], $nazwy('Zważ mąkę, ugotuj makaron i podaj z masłem.'));
        $this->assertSame([], $nazwy('Połóż ciasto na poduszce z ręcznika.'), 'Rdzeń liczy się od granicy słowa, nie ze środka.');
    }

    /** @return array<string, array{0: string}> */
    public static function odmianaPasteryzacji(): array
    {
        return [
            'polecenie' => ['Pasteryzuj słoiki.'],
            'bezokolicznik' => ['Pasteryzować przetwory.'],
            'rzeczownik' => ['Metoda pasteryzacji zależy od produktu.'],
            'dokonane' => ['Spasteryzuj przetwory.'],
            'mleko w kroku' => ['Wlej do garnka mleko pasteryzowane.'],
        ];
    }

    /**
     * Decyzja właściciela z 2.10.2026 (D-333, #2343): hasło „Pasteryzować”
     * jest usunięte do czasu przeglądu merytorycznego ze źródłem i datą
     * (#2434). Krok z pasteryzacją albo z „mlekiem pasteryzowanym” nie
     * pokazuje żadnego objaśnienia.
     */
    #[DataProvider('odmianaPasteryzacji')]
    public function test_krok_z_pasteryzacja_nie_pokazuje_objasnienia_do_czasu_przegladu(string $instruction): void
    {
        $this->assertSame([], SlownikTerminow::wTekscie($instruction), 'PASTERYZACJA_USUNIETA_DO_PRZEGLADU');
        $this->assertNotContains('Pasteryzować', array_column(SlownikTerminow::HASLA, 'haslo'), 'PASTERYZACJA_USUNIETA_DO_PRZEGLADU');

        $recipe = $this->przepisZKrokiem($instruction);
        $html = (string) $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('cook-terminy', $html, 'PASTERYZACJA_USUNIETA_DO_PRZEGLADU');
        $this->assertStringContainsString($instruction, $html, 'Treść przepisu użytkownika ma pozostać bez zmian.');
    }

    public function test_uwaga_pod_objasnieniami_nie_obiecuje_oceny_bezpieczenstwa_ani_parametrow_autora(): void
    {
        $recipe = $this->przepisZKrokiem('Zahartuj śmietanę.');
        $html = (string) $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<details class="cook-terminy".*?<\/details>/s', $html, $block));
        $this->assertStringContainsString('To objaśnienie słowa, nie ocena bezpieczeństwa przepisu.', $block[0], 'PASTERYZACJA_UWAGA_NIE_GWARANTUJE');
        $this->assertStringContainsString('Przy przetworach korzystaj z przebadanych zaleceń dla konkretnego produktu.', $block[0], 'PASTERYZACJA_UWAGA_NIE_GWARANTUJE');
        $this->assertStringNotContainsString('trzymaj się tego, co napisał autor', $block[0], 'PASTERYZACJA_UWAGA_NIE_GWARANTUJE');
    }

    private function wyjasnienie(string $haslo): string
    {
        foreach (SlownikTerminow::HASLA as $wpis) {
            if ($wpis['haslo'] === $haslo) {
                return $wpis['wyjasnienie'];
            }
        }

        $this->fail("Brak hasła „{$haslo}” w słowniku.");
    }

    /**
     * Decyzje właściciela z 2.10.2026 (D-333, #2343): marynowanie mięsa
     * i ryby w lodówce, jajka w deserach niepieczonych, dłuższe ostrzeżenie
     * przy flambirowaniu.
     */
    public function test_objasnienia_maja_ostrzezenia_z_decyzji_wlasciciela(): void
    {
        $this->assertStringContainsString('na kilka godzin lub na noc, mięso i rybę w lodówce.', $this->wyjasnienie('Marynować'), 'SLOWNIK_MARYNOWANIE_W_LODOWCE');
        $this->assertStringContainsString('W deserach, których się nie piecze, używaj jajek pasteryzowanych albo z pewnego źródła.', $this->wyjasnienie('Sztywna piana'), 'SLOWNIK_PIANA_JAJKA');
        $flambir = $this->wyjasnienie('Flambirować');
        foreach (['wyłącz okap i wentylator nad płytą', 'odsuń ręczniki i zasłony', 'nie rób tego przy dzieciach', 'Odsuń twarz', 'trzymaj pod ręką przykrywkę'] as $ostrzezenie) {
            $this->assertStringContainsString($ostrzezenie, $flambir, 'SLOWNIK_FLAMBIR_OSTRZEZENIE');
        }
    }

    /**
     * Decyzja właściciela z 2.10.2026 (D-333): „sparzyć się” i „oparzyć” to
     * oparzenie, nie technika; „zaprawić ogórki” i „zaprawiać słoiki” to
     * przetwory, nie zupa.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function rdzenieZInnymZnaczeniem(): array
    {
        return [
            'sparz pomidory' => ['Sparz pomidory i obierz ze skórki.', ['Sparzyć']],
            'sparzone pomidory' => ['Sparzone pomidory pokrój w kostkę.', ['Sparzyć']],
            'migdały sparzyć' => ['Migdały sparzyć wrzątkiem.', ['Sparzyć']],
            'sparzyć się' => ['Uważaj, żeby się nie sparzyć parą.', []],
            'sparz się' => ['Nie sparz się przy odcedzaniu.', []],
            'oparzyć' => ['Gorący garnek może oparzyć dłonie.', []],
            'zaprawić zupę' => ['Zaprawić zupę śmietaną.', ['Zaprawić zupę']],
            'zupę zaprawić' => ['Zupę zaprawić mąką rozmieszaną z wodą.', ['Zaprawić zupę']],
            'zapraw sos' => ['Zapraw sos śmietaną.', ['Zaprawić zupę']],
            'zaprawić ogórki' => ['Zaprawić ogórki w słoikach.', []],
            'zaprawiać słoiki' => ['Zaprawiać słoiki wieczorem.', []],
        ];
    }

    /** @param list<string> $oczekiwane */
    #[DataProvider('rdzenieZInnymZnaczeniem')]
    public function test_rdzenie_nie_lapia_oparzenia_ani_przetworow(string $tekst, array $oczekiwane): void
    {
        $this->assertSame($oczekiwane, array_column(SlownikTerminow::wTekscie($tekst), 'haslo'), 'SLOWNIK_RDZEN_INNE_ZNACZENIE');
    }

    /**
     * Decyzja właściciela z 2.10.2026 (D-333): karmel z cukru i cebula
     * karmelizowana to dwa hasła; ostrzeżenie o oparzeniu ma tylko karmel.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function karmelizowanie(): array
    {
        return [
            'skarmelizuj cebulę' => ['Skarmelizuj cebulę na maśle.', ['Cebula karmelizowana']],
            'cebulę karmelizuj' => ['Cebulę karmelizuj przez 20 minut.', ['Cebula karmelizowana']],
            'karmelizowana cebula' => ['Dodaj karmelizowaną cebulę.', ['Cebula karmelizowana']],
            'karmelizuj cukier' => ['Karmelizuj cukier na złoty kolor.', ['Karmel z cukru']],
            'skarmelizuj cukier' => ['Skarmelizuj cukier w rondlu.', ['Karmel z cukru']],
            'polej karmelem' => ['Polej deser karmelem.', ['Karmel z cukru']],
        ];
    }

    /** @param list<string> $oczekiwane */
    #[DataProvider('karmelizowanie')]
    public function test_karmel_z_cukru_i_cebula_karmelizowana_to_osobne_hasla(string $tekst, array $oczekiwane): void
    {
        $this->assertSame($oczekiwane, array_column(SlownikTerminow::wTekscie($tekst), 'haslo'), 'SLOWNIK_KARMEL_ROZDZIELONY');
    }

    public function test_ostrzezenie_o_oparzeniu_ma_tylko_karmel_z_cukru(): void
    {
        $this->assertStringContainsString('mocno oparza', $this->wyjasnienie('Karmel z cukru'), 'SLOWNIK_KARMEL_ROZDZIELONY');
        $this->assertStringNotContainsString('oparza', $this->wyjasnienie('Cebula karmelizowana'), 'SLOWNIK_KARMEL_ROZDZIELONY');
    }

    public function test_slownik_ma_poprawna_budowe_i_zasady_tekstow(): void
    {
        $nazwy = [];
        foreach (SlownikTerminow::HASLA as $haslo) {
            $this->assertNotSame('', trim($haslo['haslo']));
            $this->assertNotContains($haslo['haslo'], $nazwy, 'Hasło się powtarza.');
            $nazwy[] = $haslo['haslo'];
            $this->assertNotSame([], $haslo['rdzenie']);
            foreach ($haslo['rdzenie'] as $rdzen) {
                $this->assertGreaterThanOrEqual(4, mb_strlen($rdzen), "Rdzeń „{$rdzen}” jest za krótki i łapałby cudze słowa.");
                $this->assertSame(mb_strtolower($rdzen), $rdzen, 'Rdzenie piszemy małymi literami.');
            }
            $this->assertLessThanOrEqual(SlownikTerminow::MAKS_DLUGOSC_WYJASNIENIA, mb_strlen($haslo['wyjasnienie']), "Wyjaśnienie „{$haslo['haslo']}” jest za długie.");
            $this->assertStringEndsWith('.', $haslo['wyjasnienie']);
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])\p{L}*(?:łeś|łaś|łem|łam)(?![\p{L}])/u', $haslo['wyjasnienie'], 'Wyjaśnienie zakłada rodzaj osoby czytającej.');
        }
        $this->assertGreaterThanOrEqual(20, count(SlownikTerminow::HASLA));
    }

    public function test_przykladowe_haslo_demo_jest_rozpoznawane_w_kroku_rosolu(): void
    {
        $this->assertSame(['Szumowiny'], array_column(SlownikTerminow::wTekscie(
            'Zagotuj na średnim ogniu, a gdy zacznie wrzeć, zbierz łyżką szumowiny. To decyduje o tym, czy rosół będzie klarowny.',
        ), 'haslo'));
    }

    public function test_rdzenie_lapia_bezokolicznik_i_nie_myla_sztywnego_ciasta_z_piana(): void
    {
        $nazwy = fn (string $tekst): array => array_column(SlownikTerminow::wTekscie($tekst), 'haslo');

        // Recenzja paczki V: „rumień” nie łapał „rumienić”, „dusz” nie łapał
        // „dusić”, a samo „sztywn” pokazywało pianę przy sztywnym cieście.
        $this->assertSame([
            'rumienić' => ['Rumienić'],
            'dusić' => ['Duszenie'],
            'piana' => ['Sztywna piana'],
            'na sztywno' => ['Sztywna piana'],
            'ciasto' => [],
        ], [
            'rumienić' => $nazwy('Cebulę rumienić na maśle.'),
            'dusić' => $nazwy('Dusić pod przykryciem 40 minut.'),
            'piana' => $nazwy('Białka ubij na sztywną pianę.'),
            'na sztywno' => $nazwy('Śmietankę ubij na sztywno.'),
            'ciasto' => $nazwy('Ciasto ma być sztywne, a mięso robi się sztywne po schłodzeniu.'),
        ]);
    }

    public function test_uwaga_pod_slownikiem_ma_rozmiar_tekstu_czytanego_nie_meta(): void
    {
        $recipe = $this->przepisZKrokiem('Zahartuj śmietanę.');

        $html = (string) $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<details class="cook-terminy".*?<\/details>/s', $html, $blok));
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertSame([
            'klasa w HTML' => 1,
            'meta w bloku' => 0,
            'reguła CSS 18 px' => 1,
        ], [
            'klasa w HTML' => preg_match('/<p class="cook-terminy-uwaga">/', $blok[0]),
            'meta w bloku' => preg_match('/class="meta"/', $blok[0]),
            'reguła CSS 18 px' => preg_match('/\.cook-terminy-uwaga\s*\{[^}]*font-size:\s*var\(--text-body\);/', $css),
        ], 'Uwaga pod słownikiem terminów zeszła do .meta albo poniżej 18 px (tekst czytany).');
    }
}
