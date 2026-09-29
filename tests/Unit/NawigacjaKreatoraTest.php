<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Livewire\Forms\PrzepisForm;
use App\Support\KreatorPrzepisu\NawigacjaKreatora;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1387, krok 8 — nawigacja kreatora wydzielona z komponentu
 * `recipe-wizard` do `NawigacjaKreatora`.
 *
 * Tabela klucz błędu → krok obejmuje WSZYSTKIE klucze, które kreator może
 * dodać do worka błędów: pola kroku „o przepisie” pod `form.<pole>` (od
 * kroku 6), zdjęcie główne, wiersze składników i przygotowania, błąd
 * „opisz przynajmniej jeden krok” oraz pola podglądu. Część wierszy
 * budowana jest z list pól (`PrzepisForm::POLA`,
 * `WierszePrzepisu::KLUCZE_BLEDOW`), więc nowe pole formularza bez wpisu
 * w mapie kroków przestanie przechodzić już tutaj, a nie dopiero u człowieka,
 * któremu link z podsumowania błędów wskazałby nieistniejące pole.
 */
final class NawigacjaKreatoraTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function kluczeBledow(): array
    {
        $wiersze = [
            // Krok 1 — o przepisie: zdjęcie główne (pola formularza niżej, z listy).
            'zdjęcie główne' => ['heroPhoto', 1],
            // Krok 2 — składniki.
            'składniki (błąd całej listy)' => ['ingredients', 2],
            'składnik: tekst' => ['ingredients.0.text', 2],
            'składnik: grupa' => ['ingredients.3.group_name', 2],
            'składnik: uwaga' => ['ingredients.12.note', 2],
            'składnik: zamiennik' => ['ingredients.1.substitutes', 2],
            // Krok 3 — przygotowanie.
            'brak kroku przygotowania' => ['steps', 3],
            'krok: opis' => ['steps.0.instruction', 3],
            'krok: minutnik' => ['steps.2.timer_minutes', 3],
            'krok: zdjęcie' => ['steps.4.photo', 3],
            // Podgląd.
            'publikacja' => ['publikacja', 4],
            'odczyt sprawdzony' => ['odczyt_sprawdzony', 4],
            'sprawdziłem odczyt' => ['sprawdzilemOdczyt', 4],
            // Nieznany klucz nie może znikać: ląduje na pierwszym kroku.
            'nieznany klucz' => ['cos_nowego', 1],
            'pusty klucz' => ['', 1],
        ];

        foreach (PrzepisForm::POLA as $pole) {
            $wiersze['pole kroku 1: form.'.$pole] = ['form.'.$pole, 1];
        }

        foreach (WierszePrzepisu::KLUCZE_BLEDOW as $wzor) {
            $krok = str_starts_with($wzor, 'ingredients.') ? 2 : 3;
            $wiersze['wiersz z listy kluczy: '.$wzor] = [str_replace('*', '7', $wzor), $krok];
        }

        return $wiersze;
    }

    #[DataProvider('kluczeBledow')]
    public function test_klucz_bledu_prowadzi_do_kroku_z_tym_polem(string $klucz, int $oczekiwanyKrok): void
    {
        $this->assertSame($oczekiwanyKrok, NawigacjaKreatora::krokDlaKlucza($klucz));
    }

    /** Klucz, który tylko ZACZYNA się jak nazwa listy, nie jest jej polem. */
    public function test_klucz_o_wspolnym_poczatku_nie_wpada_do_kroku_listy(): void
    {
        $this->assertSame(1, NawigacjaKreatora::krokDlaKlucza('stepsX'));
        $this->assertSame(1, NawigacjaKreatora::krokDlaKlucza('ingredientsX.0.text'));
    }

    public function test_dalej_idzie_o_krok_i_zatrzymuje_sie_na_podgladzie(): void
    {
        $this->assertSame(2, NawigacjaKreatora::nastepny(1));
        $this->assertSame(3, NawigacjaKreatora::nastepny(2));
        $this->assertSame(4, NawigacjaKreatora::nastepny(3));
        $this->assertSame(4, NawigacjaKreatora::nastepny(4));
    }

    public function test_wstecz_cofa_o_krok_i_zatrzymuje_sie_na_pierwszym(): void
    {
        $this->assertSame(3, NawigacjaKreatora::poprzedni(4));
        $this->assertSame(2, NawigacjaKreatora::poprzedni(3));
        $this->assertSame(1, NawigacjaKreatora::poprzedni(2));
        $this->assertSame(1, NawigacjaKreatora::poprzedni(1));
    }

    public function test_liczniki_krokow_zgadzaja_sie_z_komponentem(): void
    {
        $this->assertSame(3, NawigacjaKreatora::KROKI);
        $this->assertSame(4, NawigacjaKreatora::KROK_PODGLADU);
    }

    /** @return array<string, array{list<string>, int}> */
    public static function bledyZdjec(): array
    {
        return [
            'tylko zdjęcie główne' => [['heroPhoto'], 1],
            'tylko zdjęcie kroku' => [['steps.1.photo'], 3],
            'oba: krok wygrywa' => [['heroPhoto', 'steps.0.photo'], 3],
            'oba w odwrotnej kolejności' => [['steps.0.photo', 'heroPhoto'], 3],
            'pole formularza obok zdjęcia głównego' => [['form.title', 'heroPhoto'], 1],
            'pusty worek' => [[], 1],
        ];
    }

    /** @param  list<string>  $klucze */
    #[DataProvider('bledyZdjec')]
    public function test_krok_po_bledzie_zdjecia(array $klucze, int $oczekiwanyKrok): void
    {
        $this->assertSame($oczekiwanyKrok, NawigacjaKreatora::krokPoBleduZdjecia($klucze));
    }

    public function test_krok_pierwszego_bledu_bierze_pierwszy_klucz_z_worka(): void
    {
        $this->assertSame(2, NawigacjaKreatora::krokPierwszegoBledu(['ingredients.1.text', 'steps.0.instruction']));
        $this->assertSame(3, NawigacjaKreatora::krokPierwszegoBledu(['steps.0.instruction', 'ingredients.1.text']));
        $this->assertSame(4, NawigacjaKreatora::krokPierwszegoBledu(['odczyt_sprawdzony']));
        $this->assertSame(1, NawigacjaKreatora::krokPierwszegoBledu([]));
    }

    public function test_identyfikator_pola_zamienia_kropki_i_nawiasy_na_myslniki(): void
    {
        $this->assertSame('f-form-title', NawigacjaKreatora::idPola('form.title'));
        $this->assertSame('f-steps-0-instruction', NawigacjaKreatora::idPola('steps.0.instruction'));
        $this->assertSame('f-heroPhoto', NawigacjaKreatora::idPola('heroPhoto'));
        // Nawiasy zamieniają się każdy osobno — tak jak przed wydzieleniem.
        $this->assertSame('f-steps-2--photo-', NawigacjaKreatora::idPola('steps[2][photo]'));
    }
}
