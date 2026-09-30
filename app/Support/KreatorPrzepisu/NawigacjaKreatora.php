<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

/**
 * Nawigacja kreatora przepisu: który krok pokazuje pole o danym kluczu błędu
 * i dokąd prowadzą „Dalej” oraz „Wstecz” (issue #1387, krok 8).
 *
 * Wydzielone z `resources/views/components/recipe-wizard.blade.php` bez
 * zmiany zachowania dla kluczy, które kreator naprawdę dodaje do worka
 * błędów. Klasa nie zna Livewire'a, worka błędów ani sesji: dostaje numer
 * kroku albo klucz błędu i zwraca numer kroku. Komponent zostaje przy tym,
 * co należy do Livewire'a — przełączeniu `$step`, zdarzeniu fokusu
 * i zapisie szkicu.
 *
 * Mapa klucz → krok (klucze takie, jakie trafiają do worka błędów):
 *
 *   krok 1  form.<pole>, heroPhoto            (a także każdy nieznany klucz)
 *   krok 2  ingredients, ingredients.N.<pole>
 *   krok 3  steps, steps.N.<pole> (instruction, timer_minutes, photo)
 *   krok 4  publikacja, odczyt_sprawdzony, sprawdzilemOdczyt  (podgląd)
 *
 * Po kroku 6 pola „o przepisie” żyją pod `form.<pole>`, więc trafiają do
 * kroku 1 przez regułę domyślną — nie przez to, że klucz zaczyna się od
 * `form`. Domyślne „krok 1” jest tu świadomą decyzją: klucz, którego nikt
 * tu nie dopisze, ląduje na pierwszym kroku, a nie znika.
 */
final class NawigacjaKreatora
{
    /** Liczba kroków pokazywana człowiekowi („Krok 2 z 3”). */
    public const KROKI = 3;

    /** Podgląd to czwarty ekran, poza licznikiem „z 3”. */
    public const KROK_PODGLADU = 4;

    /** Klucze błędów, których pole stoi na podglądzie. */
    private const KLUCZE_PODGLADU = ['publikacja', 'odczyt_sprawdzony', 'sprawdzilemOdczyt'];

    /** Krok po „Dalej” — na podglądzie zostaje podgląd. */
    public static function nastepny(int $krok): int
    {
        return min($krok + 1, self::KROK_PODGLADU);
    }

    /** Krok po „Wstecz” — na pierwszym kroku zostaje pierwszy. */
    public static function poprzedni(int $krok): int
    {
        return max($krok - 1, 1);
    }

    /**
     * Który krok pokazuje pole o tym kluczu błędu (issue #747).
     *
     * Podsumowanie błędów zbiera klucze ze WSZYSTKICH kroków naraz, a `back()`
     * potrafi zostawić błąd z kroku 3 i zejść na krok 2, więc odnośnik musi
     * wiedzieć, gdzie naprawdę stoi jego pole.
     */
    public static function krokDlaKlucza(string $klucz): int
    {
        return match (true) {
            $klucz === 'ingredients', str_starts_with($klucz, 'ingredients.') => 2,
            // Oznaczenie alergenów (#1902) stoi pod składnikami.
            $klucz === 'alergeny' => 2,
            // `steps` to błąd „opisz przynajmniej jeden krok”, `steps.N.…` to pola wierszy.
            $klucz === 'steps', str_starts_with($klucz, 'steps.') => 3,
            in_array($klucz, self::KLUCZE_PODGLADU, true) => self::KROK_PODGLADU,
            default => 1,
        };
    }

    /**
     * Krok do pokazania po odrzuconym zdjęciu (issue #747): błąd stoi przy
     * zdjęciu kroku (`steps.N.photo`, krok 3) ALBO przy zdjęciu głównym
     * (`heroPhoto`, krok 1). Krok 3, jeśli jest tam choć jeden błąd.
     *
     * @param  list<string>  $klucze  Klucze z worka błędów.
     */
    public static function krokPoBleduZdjecia(array $klucze): int
    {
        foreach ($klucze as $klucz) {
            if (self::krokDlaKlucza($klucz) === 3) {
                return 3;
            }
        }

        return 1;
    }

    /**
     * Krok do pokazania po błędzie bramki odczytu (D-298): krok pierwszego
     * błędu w worku. Pusty worek to krok 1, jak dla nieznanego klucza.
     *
     * @param  list<string>  $klucze  Klucze z worka błędów, w kolejności dodania.
     */
    public static function krokPierwszegoBledu(array $klucze): int
    {
        return self::krokDlaKlucza((string) ($klucze[0] ?? ''));
    }

    /**
     * Identyfikator elementu, na którym przeglądarka ustawia fokus:
     * `form.title` → `f-form-title`, `steps.0.instruction` → `f-steps-0-instruction`.
     * Ten sam wzór stoi w `id` pól i w `href` podsumowania błędów.
     */
    public static function idPola(string $klucz): string
    {
        return 'f-'.str_replace(['[', ']', '.'], '-', $klucz);
    }
}
