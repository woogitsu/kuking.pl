<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

use App\Livewire\Forms\PrzepisForm;

/**
 * Walidacja kroków kreatora jako czysta funkcja stanu (issue #1387, krok 10).
 *
 * Reguły i komunikaty mają swoje nazwane źródła (`KrokOPrzepisie`,
 * `WierszePrzepisu`). Ta klasa składa je w to, czego potrzebuje komponent
 * po każdej walidacji: które klucze worka błędów wyczyścić, jakie błędy
 * dodać (w kolejności, w jakiej mają trafić do worka) i który krok pokazać.
 * Nie zna Livewire'a, worka błędów ani bazy — dostaje surowe pola, dawny adres
 * z bazy i wiersze, a komponent zostaje przy tym, co jego: `resetErrorBag()`,
 * `addError()` i przełączeniu `$step`.
 */
final class WalidacjaKreatora
{
    /**
     * Krok „o przepisie”: klucze, które ta walidacja rozstrzyga (i które
     * komponent czyści przed ponownym sprawdzeniem), oraz błędy pod
     * kluczami `form.<pole>`.
     *
     * @param  array<string, mixed>  $pola  wartości pól z `PrzepisForm::pola()`
     * @param  ?string  $dawnyAdres  `source_url` zapisany w BAZIE albo null
     */
    public static function krokOPrzepisie(array $pola, ?string $dawnyAdres): WynikWalidacjiKreatora
    {
        $walidator = KrokOPrzepisie::walidator($pola, $dawnyAdres);

        $bledy = [];
        if ($walidator->fails()) {
            foreach ($walidator->errors()->messages() as $klucz => $komunikaty) {
                foreach ($komunikaty as $komunikat) {
                    $bledy[PrzepisForm::kluczBledu($klucz)][] = $komunikat;
                }
            }
        }

        return new WynikWalidacjiKreatora(
            array_map(PrzepisForm::kluczBledu(...), array_keys($walidator->getData())),
            $bledy,
        );
    }

    /**
     * Wiersze składników i przygotowania: najpierw wszystkie błędy składników,
     * potem kroków (tak dokładnie, jak trafiały do worka przed wydzieleniem).
     *
     * @param  array<array-key, array<string, mixed>>  $skladniki
     * @param  array<array-key, array<string, mixed>>  $kroki
     */
    public static function wiersze(array $skladniki, array $kroki): WynikWalidacjiKreatora
    {
        $bledy = [];
        foreach ([...WierszePrzepisu::bledySkladnikow($skladniki), ...WierszePrzepisu::bledyKrokow($kroki)] as $klucz => $komunikat) {
            $bledy[$klucz][] = $komunikat;
        }

        return new WynikWalidacjiKreatora(WierszePrzepisu::KLUCZE_BLEDOW, $bledy);
    }

    /**
     * Krok, na którym stoi pierwszy błąd wierszy: składniki (2) przed
     * przygotowaniem (3). Bez błędów wierszy — null, czyli zostajemy, gdzie
     * jesteśmy.
     */
    public static function krokZBledemWierszy(WynikWalidacjiKreatora $wynik): ?int
    {
        $klucze = array_keys($wynik->bledy);

        foreach ($klucze as $klucz) {
            if (str_starts_with($klucz, 'ingredients.')) {
                return 2;
            }
        }

        foreach ($klucze as $klucz) {
            if (str_starts_with($klucz, 'steps.')) {
                return 3;
            }
        }

        return null;
    }

    /**
     * Zdanie przy braku jakiegokolwiek kroku przygotowania. Składników nie
     * wymagamy (zgoda właściciela z 11.09.2026, issue #364).
     */
    public static function brakKrokuPrzygotowania(bool $juzOpublikowany): string
    {
        return $juzOpublikowany
            ? 'Opisz przynajmniej jeden krok przygotowania, żeby zapisać zmiany. Tekst jest dalej w formularzu.'
            : 'Opisz przynajmniej jeden krok przygotowania, żeby opublikować przepis. Nic nie zginęło — resztę masz zapisaną w szkicu.';
    }
}
