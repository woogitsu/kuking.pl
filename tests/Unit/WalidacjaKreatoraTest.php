<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KreatorPrzepisu\KrokOPrzepisie;
use App\Support\KreatorPrzepisu\WalidacjaKreatora;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use App\Support\KreatorPrzepisu\WynikWalidacjiKreatora;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1387, krok 10 — składanie walidacji kroków kreatora w błędy dla
 * worka komponentu i wybór kroku do pokazania. Bez renderowania kreatora
 * i bez bazy.
 */
final class WalidacjaKreatoraTest extends TestCase
{
    /** @return array<string, mixed> */
    private function poprawnePola(): array
    {
        return [
            'title' => 'Rosół', 'summary' => '', 'servings' => '', 'prep_minutes' => '',
            'cook_minutes' => '', 'difficulty' => '', 'visibility' => 'public',
            'source_type' => 'own', 'source_person' => '', 'source_note' => '',
            'source_url' => '', 'family_since_year' => '',
        ];
    }

    /** @return array<string, array{0: array<string, mixed>, 1: list<string>}> */
    public static function krokOPrzepisieProvider(): array
    {
        return [
            'poprawne pola' => [[], []],
            'pusta nazwa' => [['title' => '   '], ['form.title']],
            'za długa nazwa' => [['title' => str_repeat('a', 181)], ['form.title']],
            'zła widoczność' => [['visibility' => 'wszyscy'], ['form.visibility']],
            'nazwa i widoczność naraz' => [['title' => '', 'visibility' => 'x'], ['form.title', 'form.visibility']],
        ];
    }

    /**
     * @param  array<string, mixed>  $zmiany
     * @param  list<string>  $oczekiwaneKlucze
     */
    #[DataProvider('krokOPrzepisieProvider')]
    public function test_krok_o_przepisie_zwraca_bledy_pod_kluczami_formularza(array $zmiany, array $oczekiwaneKlucze): void
    {
        $pola = [...$this->poprawnePola(), ...$zmiany];

        $wynik = WalidacjaKreatora::krokOPrzepisie($pola, null);

        $this->assertEqualsCanonicalizing($oczekiwaneKlucze, array_keys($wynik->bledy));
        $this->assertSame($oczekiwaneKlucze === [], $wynik->poprawny());
        $this->assertContains('form.title', $wynik->sprawdzone);
        $this->assertNotContains('title', $wynik->sprawdzone, 'Klucze do wyczyszczenia mają przedrostek form.');

        // Komunikaty pochodzą z jednego źródła, w tej samej kolejności co walidator.
        $walidator = KrokOPrzepisie::walidator($pola, null);
        foreach ($wynik->bledy as $klucz => $komunikaty) {
            $this->assertSame($walidator->errors()->get(substr($klucz, 5)), $komunikaty);
        }
    }

    /**
     * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: list<string>, 3: ?int}>
     */
    public static function wierszeProvider(): array
    {
        $dlugi = str_repeat('a', 5000);

        return [
            'puste wiersze' => [[], [], [], null],
            'poprawne wiersze' => [[['text' => 'mąka']], [['instruction' => 'Wymieszaj', 'timer_minutes' => '5']], [], null],
            'za długi składnik' => [[['text' => $dlugi]], [], ['ingredients.0.text'], 2],
            'za długi krok' => [[], [['instruction' => $dlugi]], ['steps.0.instruction'], 3],
            'zły minutnik' => [[], [['instruction' => 'x', 'timer_minutes' => 'abc']], ['steps.0.timer_minutes'], 3],
            'składniki przed krokami, krok 2 wygrywa' => [
                [['text' => 'ok'], ['text' => $dlugi, 'note' => str_repeat('n', 301)]],
                [['instruction' => $dlugi]],
                ['ingredients.1.text', 'ingredients.1.note', 'steps.0.instruction'],
                2,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $skladniki
     * @param  array<int, array<string, mixed>>  $kroki
     * @param  list<string>  $oczekiwaneKlucze
     */
    #[DataProvider('wierszeProvider')]
    public function test_wiersze_zwracaja_bledy_w_kolejnosci_i_krok_do_pokazania(array $skladniki, array $kroki, array $oczekiwaneKlucze, ?int $krok): void
    {
        $wynik = WalidacjaKreatora::wiersze($skladniki, $kroki);

        $this->assertSame($oczekiwaneKlucze, array_keys($wynik->bledy));
        $this->assertSame(WierszePrzepisu::KLUCZE_BLEDOW, $wynik->sprawdzone);
        $this->assertSame($krok, WalidacjaKreatora::krokZBledemWierszy($wynik));
        $this->assertSame($oczekiwaneKlucze === [], $wynik->poprawny());

        foreach ($wynik->bledy as $komunikaty) {
            $this->assertCount(1, $komunikaty);
        }
    }

    public function test_krok_z_bledem_ignoruje_klucze_spoza_wierszy(): void
    {
        $this->assertNull(WalidacjaKreatora::krokZBledemWierszy(new WynikWalidacjiKreatora([], ['form.title' => ['x'], 'steps' => ['y']])));
    }

    public function test_brak_kroku_przygotowania_mowi_co_zrobic_zaleznie_od_stanu_przepisu(): void
    {
        $this->assertSame(
            'Opisz przynajmniej jeden krok przygotowania, żeby opublikować przepis. Nic nie zginęło — resztę masz zapisaną w szkicu.',
            WalidacjaKreatora::brakKrokuPrzygotowania(false),
        );
        $this->assertSame(
            'Opisz przynajmniej jeden krok przygotowania, żeby zapisać zmiany. Tekst jest dalej w formularzu.',
            WalidacjaKreatora::brakKrokuPrzygotowania(true),
        );
    }
}
