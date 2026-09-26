<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\StepTimer;
use App\Http\Requests\Recipes\ZapisPrzepisuRequest;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use App\Support\LimityTekstuPrzepisu;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1387, krok 2 — walidacja i normalizacja wierszy składników i kroków
 * wydzielone z komponentu `recipe-wizard` do `WierszePrzepisu`.
 *
 * Testy chodzą BEZ renderowania kreatora i bez bazy: klasa dostaje surowe
 * tablice wierszy i zwraca mapę błędów albo oczyszczone wiersze. Test
 * kontraktowy na końcu porównuje werdykt kreatora z regułami formularza bez
 * JavaScriptu (`ZapisPrzepisuRequest`) tam, gdzie oba wejścia znaczą to samo.
 */
final class WierszePrzepisuKreatoraTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function polaSkladnika(): array
    {
        return [
            'tekst składnika' => ['text', 'ingredients.*.text'],
            'nazwa grupy' => ['group_name', 'ingredients.*.group_name'],
            'uwaga' => ['note', 'ingredients.*.note'],
        ];
    }

    #[DataProvider('polaSkladnika')]
    public function test_pole_skladnika_na_granicy_przechodzi_a_znak_wiecej_nie(string $pole, string $klucz): void
    {
        $limit = LimityTekstuPrzepisu::POLA[$klucz];

        // Znaki wielobajtowe i spacje dookoła: liczymy ZNAKI po przycięciu.
        $naGranicy = ['text' => 'mąka', $pole => '  '.str_repeat('ż', $limit).'  '];
        $zaDlugo = ['text' => 'mąka', $pole => str_repeat('ż', $limit + 1)];

        $this->assertSame([], WierszePrzepisu::bledySkladnikow([$naGranicy]));
        $this->assertSame(
            ["ingredients.0.{$pole}" => WierszePrzepisu::KOMUNIKATY[$klucz]],
            WierszePrzepisu::bledySkladnikow([$zaDlugo]),
        );
    }

    public function test_instrukcja_kroku_na_granicy_przechodzi_a_znak_wiecej_nie(): void
    {
        $limit = LimityTekstuPrzepisu::POLA['steps.*.instruction'];

        $this->assertSame([], WierszePrzepisu::bledyKrokow([['instruction' => str_repeat('ż', $limit)]]));
        $this->assertSame(
            ['steps.0.instruction' => WierszePrzepisu::KOMUNIKATY['steps.*.instruction']],
            WierszePrzepisu::bledyKrokow([['instruction' => str_repeat('ż', $limit + 1)]]),
        );
    }

    public function test_komunikat_podaje_te_sama_liczbe_co_granica(): void
    {
        foreach (WierszePrzepisu::KOMUNIKATY as $klucz => $komunikat) {
            $this->assertStringContainsString(
                'najwyżej '.LimityTekstuPrzepisu::POLA[$klucz].' znaków',
                $komunikat,
                "Komunikat {$klucz} obiecuje inną liczbę niż granica, którą sprawdza.",
            );
        }
    }

    public function test_klucze_bledow_wskazuja_prawdziwy_indeks_wiersza_w_kolejnosci_pol(): void
    {
        $dlugi = str_repeat('a', 5000);

        $bledy = WierszePrzepisu::bledySkladnikow([
            0 => ['text' => 'sól'],
            3 => ['text' => $dlugi, 'group_name' => $dlugi, 'note' => $dlugi],
        ]);

        $this->assertSame(['ingredients.3.text', 'ingredients.3.group_name', 'ingredients.3.note'], array_keys($bledy));
    }

    public function test_minutnik_mowi_zdaniami_step_timer(): void
    {
        $bledy = WierszePrzepisu::bledyKrokow([
            ['instruction' => 'Gotuj.', 'timer_minutes' => ''],
            ['instruction' => 'Gotuj.', 'timer_minutes' => '45'],
            ['instruction' => 'Gotuj.', 'timer_minutes' => 'abc'],
            ['instruction' => 'Gotuj.', 'timer_minutes' => '-5'],
            ['instruction' => 'Gotuj.', 'timer_minutes' => (string) (StepTimer::MAX_MINUTES + 1)],
            ['instruction' => 'Gotuj.'],
        ]);

        $this->assertSame([
            'steps.2.timer_minutes' => StepTimer::KOMUNIKAT_NIE_LICZBA,
            'steps.3.timer_minutes' => StepTimer::KOMUNIKAT_UJEMNY,
            'steps.4.timer_minutes' => StepTimer::KOMUNIKAT_ZA_DUZO,
        ], $bledy);
    }

    public function test_klucze_do_czyszczenia_obejmuja_kazde_pole_z_komunikatem(): void
    {
        foreach (array_keys(WierszePrzepisu::KOMUNIKATY) as $klucz) {
            $this->assertContains($klucz, WierszePrzepisu::KLUCZE_BLEDOW);
        }
        $this->assertContains('steps.*.timer_minutes', WierszePrzepisu::KLUCZE_BLEDOW);
        $this->assertNotContains('steps', WierszePrzepisu::KLUCZE_BLEDOW, 'Brak kroku pilnuje publikacja, nie sprawdzenie wierszy.');
        $this->assertNotContains('steps.*.photo', WierszePrzepisu::KLUCZE_BLEDOW, 'Zdjęcie ma własny stan błędu uploadu.');
    }

    public function test_skladniki_pomijaja_puste_wiersze_przycinaja_i_zamieniaja_puste_na_null(): void
    {
        $skladniki = WierszePrzepisu::skladniki([
            ['_key' => 'w1', 'group_name' => '', 'text' => '   ', 'note' => 'coś', 'no_amount' => true],
            ['_key' => 'w2', 'group_name' => '  Ciasto ', 'text' => ' mąka 500 g ', 'note' => '', 'no_amount' => false],
            ['_key' => 'w3', 'group_name' => str_repeat('g', 200), 'text' => str_repeat('t', 300), 'note' => str_repeat('n', 400), 'no_amount' => '1'],
        ]);

        $this->assertSame([
            ['text' => 'mąka 500 g', 'group_name' => 'Ciasto', 'note' => null, 'no_amount' => false],
            ['text' => str_repeat('t', 240), 'group_name' => str_repeat('g', 120), 'note' => str_repeat('n', 300), 'no_amount' => true],
        ], $skladniki);
    }

    public function test_kroki_pomijaja_puste_nie_przekazuja_id_i_niosa_zdjecie_z_wiersza(): void
    {
        $kroki = WierszePrzepisu::kroki([
            ['_key' => 'w1', 'instruction' => '  ', 'timer_minutes' => '10', 'mediaId' => 'zdjecie-pustego', 'photo' => null],
            ['_key' => 'w2', 'id' => 'podrzucone-id', 'instruction' => ' Zagotuj wodę. ', 'timer_minutes' => '10', 'mediaId' => 'zdjecie-1', 'photo' => null],
            ['_key' => 'w3', 'instruction' => str_repeat('k', 4100)],
        ]);

        $this->assertSame([
            ['id' => null, 'instruction' => 'Zagotuj wodę.', 'timer_minutes' => '10', 'media_id' => 'zdjecie-1'],
            ['id' => null, 'instruction' => str_repeat('k', 4000), 'timer_minutes' => '', 'media_id' => null],
        ], $kroki);
    }

    // -----------------------------------------------------------------
    // Kontrakt z formularzem bez JavaScriptu (ZapisPrzepisuRequest)
    // -----------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function polaWspolne(): array
    {
        return [
            'tekst składnika' => ['ingredients', 'text'],
            'nazwa grupy' => ['ingredients', 'group_name'],
            'uwaga' => ['ingredients', 'note'],
            'instrukcja kroku' => ['steps', 'instruction'],
        ];
    }

    #[DataProvider('polaWspolne')]
    public function test_granica_dlugosci_ta_sama_co_w_formularzu_bez_javascriptu(string $tablica, string $pole): void
    {
        $limit = LimityTekstuPrzepisu::POLA["{$tablica}.*.{$pole}"];

        foreach ([$limit - 1, $limit, $limit + 1] as $dlugosc) {
            $wartosc = str_repeat('ż', $dlugosc);

            $this->assertSame(
                $this->bezJavascriptuOdrzuca($tablica, $pole, $wartosc),
                $this->kreatorOdrzuca($tablica, $pole, $wartosc),
                "Kreator i formularz bez JavaScriptu różnią się dla {$tablica}.*.{$pole} o długości {$dlugosc}.",
            );
        }
    }

    /** @return array<string, array{string}> */
    public static function wartosciMinutnika(): array
    {
        return [
            'puste' => [''],
            'zero' => ['0'],
            'zwykłe' => ['45'],
            'górna granica' => [(string) StepTimer::MAX_MINUTES],
            'ponad granicę' => [(string) (StepTimer::MAX_MINUTES + 1)],
            'ujemne' => ['-5'],
            'słowo' => ['abc'],
            'ułamek z kropką' => ['4.5'],
            'ułamek z przecinkiem' => ['4,5'],
        ];
    }

    #[DataProvider('wartosciMinutnika')]
    public function test_minutnik_ten_sam_werdykt_co_w_formularzu_bez_javascriptu(string $minuty): void
    {
        $this->assertSame(
            $this->bezJavascriptuOdrzuca('steps', 'timer_minutes', $minuty),
            $this->kreatorOdrzuca('steps', 'timer_minutes', $minuty),
        );
    }

    private function kreatorOdrzuca(string $tablica, string $pole, string $wartosc): bool
    {
        $bledy = $tablica === 'ingredients'
            ? WierszePrzepisu::bledySkladnikow([['text' => 'mąka', $pole => $wartosc]])
            : WierszePrzepisu::bledyKrokow([['instruction' => 'Gotuj.', $pole => $wartosc]]);

        return array_key_exists("{$tablica}.0.{$pole}", $bledy);
    }

    private function bezJavascriptuOdrzuca(string $tablica, string $pole, string $wartosc): bool
    {
        $klucz = "{$tablica}.*.{$pole}";
        $reguly = ZapisPrzepisuRequest::create('/dodaj/przepis', 'POST')->rules();

        return Validator::make([$tablica => [[$pole => $wartosc]]], [$klucz => $reguly[$klucz]])->fails();
    }
}
